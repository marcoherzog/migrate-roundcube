<?php

class MigrateRoundcube {

    protected $users, $contacts, $contactgroups;

    public function __construct()
    {
        $this->setCredentials();
    }

    public function init()
    {

        $this->importDB($this->dump_file,$this->dbname_source);

        $this->importContacts();
        $this->importContactgroups();
        $this->mapContactsWithContactgroups();
        $this->importIdentities();

        // DEV
        // echo "\n";
        // $this->dropContacts();
        // $this->dropContactgroups();
        // $this->dropContactgroupmembers();

        $this->dropDB($this->dbname_source);

        echo "\n";
        echo '----- MATCHING USERS -----' . "\n";
        foreach($this->users as $user) {
            echo $user['user_id_source'] . '>>' . $user['user_id'] . ' - ' . $user['username'] . "\n";
        }

        echo "\n";
        echo '----- NEW CONTACTS -----' . "\n";
        foreach($this->contacts as $contact) {
            echo $contact['contact_id'] . '>>' . $contact['contact_id_target'] . ' - ' . $contact['words'] . "\n";
        }

        echo "\n";
        echo '----- NEW CONTACTGROUPS -----' . "\n";
        foreach($this->contactgroups as $contactgroup) {
            echo $contactgroup['contactgroup_id'] . '>>' . $contactgroup['contactgroup_id_target'] . ' - ' . $contactgroup['name'] . "\n";
        }


    }

    private function setCredentials()
    {
        $this->host = "localhost";
        $this->db_user_name = "root";
        $this->db_user_pass = "";
        $this->dbname = "roundcubemail";
        $this->dbname_source = "roundcubemail_import";
        $this->dump_file = "roundcubemail.sql";

    }

    private function setConnection($target = 'db')
    {

        switch ($target) {

            case 'db':
                // Create connection
                $conn = new mysqli($this->host, $this->db_user_name, $this->db_user_pass);
                // Check connection
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error . "\n");
                }
                break;

            case $this->dbname:
                $conn = new mysqli($this->host, $this->db_user_name, $this->db_user_pass, $this->dbname);
                // Check connection
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error . "\n");
                }
                break;

            case $this->dbname_source:
                $conn = new mysqli($this->host, $this->db_user_name, $this->db_user_pass, $this->dbname_source);
                // Check connection
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error . "\n");
                }
                break;

        }

        return $conn;

    }

    private function closeConnection($conn = null)
    {
        if ($conn === null)
            return;

        $conn->close();

    }

    private function echoTargetUsers()
    {
        $conn = $this->setConnection($this->dbname);

        $sql = "SELECT user_id, username FROM users";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {

            // output data of each row
            while($row = $result->fetch_assoc()) {

                echo "user_id: " . $row["user_id"]. " - username: " . $row["username"]. " " . "\n";

            }

        } else {

            echo "0 results";

        }

        $this->closeConnection($conn);

    }

    private function createDB($db_name = null){
        if ($db_name === null)
            return;

        $conn = $this->setConnection();

        // Create database
        $sql = "CREATE DATABASE {$db_name}";
        if ($conn->query($sql) === TRUE) {
            echo "Database created successfully" . "\n";
        } else {
            echo "Error creating database: " . $conn->error . "\n";
        }

        $this->closeConnection($conn);
    }

    private function dropDB($db_name = null){
        if ($db_name === null)
            return;

        $conn = $this->setConnection();

        // Create database
        $sql = "DROP DATABASE {$db_name}";
        if ($conn->query($sql) === TRUE) {
            echo "Database deleted successfully" . "\n";
        } else {
            echo "Error deleting database: " . $conn->error . "\n";
        }

        $this->closeConnection($conn);
    }

    private function importDB($file_name = null, $target_db = null) {

        if ($file_name === null || $target_db === null)
            return;

        $this->createDB($target_db);

        $sql = file_get_contents($file_name);

        $conn = $this->setConnection($this->dbname_source);

        /* execute multi query */
        $conn->multi_query($sql);

        // It is recommended to use do-while to process multiple queries. The connection will be busy until all queries have completed and their results are fetched to PHP.
        // https://www.php.net/manual/de/mysqli.multi-query.php
        do {
            /* store the result set in PHP */
            if ($result = $conn->store_result()) {
                while ($row = $result->fetch_row()) {
                    printf("%s\n", $row[0]);
                }
            }
            /* print divider */
            if ($conn->more_results()) {
                printf("-");
            }
        } while ($conn->next_result());
        echo "\n";
        $this->closeConnection($conn);

    }

    private function importContacts() {

        $this->users = $this->matchUsers();
        $this->contacts = $this->getMissingContacts($this->users);
        echo "\n" . sizeof($this->contacts) . ' contact(s) to be imported' . "\n";
        $this->addMissingContacts($this->users, $this->contacts);

    }

    private function dropContacts() {

        $db = $this->setConnection($this->dbname);

        $sql = "DELETE FROM contacts";

        if (mysqli_query($db, $sql)) {
            echo "All contact records deleted successfully" . "\n";
        } else {
            echo "Error: " . $sql . "\n" . mysqli_error($db);
        }

        $sql = "ALTER TABLE contacts AUTO_INCREMENT = 1";
        mysqli_query($db, $sql);

        $this->closeConnection($db);
    }

    private function addMissingContacts($users = null, $contacts = null) {
        if (empty($this->users) || empty($this->contacts))
            return;

//        $sql = '';
        $db = $this->setConnection($this->dbname);

        foreach ($this->contacts as $key => $contact) {

//            echo print_r($contact, true) . "\n";

            // contact_id, changed, del, name, email, firstname, surname, vcard, words, user_id
            $newUserId = $this->getNewUserId($contact['user_id'], $this->users);
            $date = date($contact['changed']);

            $name = $db->real_escape_string($contact['name']);
            $email = $db->real_escape_string($contact['email']);
            $firstname = $db->real_escape_string($contact['firstname']);
            $surname = $db->real_escape_string($contact['surname']);
            $vcard = $db->real_escape_string($contact['vcard']);
            $words = $db->real_escape_string($contact['words']);


            $sql = "INSERT INTO contacts (`changed`,`del`,`name`,`email`,`firstname`,`surname`,`vcard`,`words`,`user_id`)
VALUES ('{$date}','{$contact['del']}','{$name}','{$email}','{$firstname}','{$surname}','{$vcard}','{$words}','{$newUserId}');";


            if (mysqli_query($db, $sql)) {
                $this->contacts[$key]['contact_id_target'] = $db->insert_id;
                //echo $db->insert_id . "\n";
                echo "New record created successfully" . $email . "\n";
            } else {
                echo "Error: " . $sql . "\n" . mysqli_error($db);
            }
        }

//        echo $sql;

//        if ($db->multi_query($sql) === TRUE) {
//            echo "New records created successfully";
//        } else {
//            echo "Error: " . $sql . "\n" . $db->error;
//        }

        $this->closeConnection($db);

    }

    private function getContacts($db_name = null, $select = '*') {
        if ($db_name === null)
            return;

        $db = $this->setConnection($db_name);

        $sql = "SELECT {$select} FROM contacts";
        $result = $db->query($sql);

        $this->closeConnection($db);

        return $result;
    }

    /**
     * get current target user_id by source user_id of contact
     * @param null $oldUserId
     * @param null $users
     * @return mixed|void
     */
    private function getNewUserId($oldUserId = null, $users = null) {
        if ($oldUserId === null || empty($this->users) )
            return;

        $user_key = array_search( $oldUserId, array_column($this->users, 'user_id_source'));
        return $this->users[$user_key]['user_id'];

    }

    private function getMissingContacts($users = null) {
        if (empty($this->users))
            return;

        $target_result = $this->getContacts($this->dbname, 'user_id, words');
        for ($target_contacts = array (); $row = $target_result->fetch_assoc(); $target_contacts[] = $row);

        $source_result = $this->getContacts($this->dbname_source);
        for ($source_contacts = array (); $row = $source_result->fetch_assoc(); $source_contacts[] = $row);

        foreach ($source_contacts as $key => $source_contact) {

            // remove all contacts not matching with existing users
            if( ! in_array($source_contact['user_id'], array_column($this->users, 'user_id_source')) ) {

                unset($source_contacts[$key]);

            } else {

                // if in array test if contact exist by comparing words column

                // get current target user_id by source user_id of contact
                $current_user_id = $this->getNewUserId($source_contact['user_id'], $this->users);

                foreach ($target_contacts as $target_contact) {

                    // unset contact if user ids match and column words match
                    if( $target_contact['user_id'] === $current_user_id && $target_contact['words'] === $source_contact['words'] ) {

                        unset($source_contacts[$key]);

                    }

                }

            }

        }

        return array_values($source_contacts); // array_values() to reindex arrays

    }

    private function getUsers($db_name = null) {
        if ($db_name === null)
            return;

        $db = $this->setConnection($db_name);

        $sql = "SELECT user_id, username FROM users";
        $result = $db->query($sql);

        $this->closeConnection($db);

        return $result;
    }

    private function matchUsers() {

        $target_result = $this->getUsers($this->dbname);

        if ($target_result->num_rows > 0) {

            $source_result = $this->getUsers($this->dbname_source);

            if ($source_result->num_rows > 0) {

                for ($target_users = array (); $row = $target_result->fetch_assoc(); $target_users[] = $row);
                for ($source_users = array (); $row = $source_result->fetch_assoc(); $source_users[] = $row);

                foreach ($target_users as $key => $target_user) {

                    if( ! in_array($target_user['username'], array_column($source_users, 'username')) ) {

                        unset($target_users[$key]);

                    } else {

//                        echo 'Match: ' . $target_user['username'] . "\n";
                        $source_key = array_search( $target_user['username'], array_column($source_users, 'username'));
                        $target_users[$key]['user_id_source'] = $source_users[$source_key]['user_id'];

                    }

                }

                return array_values($target_users); // array_values() to reindex arrays

            }

        }

    }

    private function importContactgroups() {

        $this->contactgroups = $this->getMissingContactgroups($this->users);
        echo "\n" . sizeof($this->contactgroups) . ' contactgroup(s) to be imported' . "\n";
        $this->addMissingContactgroups($this->users, $this->contactgroups);

    }

    private function getMissingContactgroups($users = null) {
        if (empty($this->users))
            return;

        $target_result = $this->getContactgroups($this->dbname, 'contactgroup_id, user_id, name');
        for ($target_contactgroups = array (); $row = $target_result->fetch_assoc(); $target_contactgroups[] = $row);

        $source_result = $this->getContactgroups($this->dbname_source);
        for ($source_contactgroups = array (); $row = $source_result->fetch_assoc(); $source_contactgroups[] = $row);

//        echo '$source_contactgroup' . "\n";
//        foreach ($source_contactgroups as $key => $source_contactgroup) {
//            echo $source_contactgroup['name'] . "\n";
//        }
//
//        echo '$target_contactgroups' . "\n";
//        foreach ($target_contactgroups as $key => $target_contactgroup) {
//            echo $target_contactgroup['name'] . "\n";
//        }

        foreach ($source_contactgroups as $key => $source_contactgroup) {

            // remove all contactgroups not matching with existing users
            if( ! in_array($source_contactgroup['user_id'], array_column($this->users, 'user_id_source')) ) {

                unset($source_contactgroups[$key]);
//                echo 'unset ' . $source_contactgroup['name'] . "\n";

            } else {

                // if in array test if contact exist by comparing name column

                // get current target user_id by source user_id of contact
                $current_user_id = $this->getNewUserId($source_contactgroup['user_id'], $this->users);

                foreach ($target_contactgroups as $target_contactgroup) {

                    // unset contact if user ids match and column words match
                    if( $target_contactgroup['user_id'] === $current_user_id && $target_contactgroup['name'] === $source_contactgroup['name'] ) {

                        unset($source_contactgroups[$key]);

                    }

                }

            }

        }
//        echo '$source_contactgroup' . "\n";
//        foreach ($source_contactgroups as $key => $source_contactgroup) {
//            echo $source_contactgroup['name'] . "\n";
//        }

        return array_values($source_contactgroups); // array_values() to reindex arrays

    }

    private function getContactgroups($db_name = null, $select = '*') {
        if ($db_name === null)
            return;

        $db = $this->setConnection($db_name);

        $sql = "SELECT {$select} FROM contactgroups";
        $result = $db->query($sql);

        $this->closeConnection($db);

        return $result;
    }

    private function addMissingContactgroups($users = null, $contacts = null) {
        if (empty($this->users) || empty($this->contactgroups))
            return;

//        $sql = '';
        $db = $this->setConnection($this->dbname);

        foreach ($this->contactgroups as $key => $contactgroup) {

//            echo print_r($contact, true) . "\n";

            // contact_id, changed, del, name, email, firstname, surname, vcard, words, user_id
            $newUserId = $this->getNewUserId($contactgroup['user_id'], $this->users);
            $date = date($contactgroup['changed']);
            $name = $db->real_escape_string($contactgroup['name']);

            $sql = "INSERT INTO contactgroups (`user_id`,`changed`,`del`,`name`)
VALUES ('{$newUserId}','{$date}','{$contactgroup['del']}','{$name}');";

            if (mysqli_query($db, $sql)) {
                $this->contactgroups[$key]['contactgroup_id_target'] = $db->insert_id;
                //echo $db->insert_id . "\n";
                echo "New contactgroup record created successfully: " . $name . "\n";
            } else {
                echo "Error: " . $sql . "\n" . mysqli_error($db);
            }
        }

        $this->closeConnection($db);

    }

    private function dropContactgroups() {

        $db = $this->setConnection($this->dbname);

        $sql = "DELETE FROM contactgroups";

        if (mysqli_query($db, $sql)) {
            echo "All contactgroup records deleted successfully" . "\n";
        } else {
            echo "Error: " . $sql . "\n" . mysqli_error($db);
        }

        $sql = "ALTER TABLE contactgroups AUTO_INCREMENT = 1";
        mysqli_query($db, $sql);

        $this->closeConnection($db);
    }

    private function mapContactsWithContactgroups() {

        if (empty($this->users))
            return;

        $db = $this->setConnection($this->dbname);

//        $target_result = $this->getContactgroups($this->dbname, 'contactgroup_id, contact_id');
//        for ($target_contactgroupmembers = array (); $row = $target_result->fetch_assoc(); $target_contactgroupmembers[] = $row);

        $source_result = $this->getContactgroupsmembers($this->dbname_source);
        for ($source_contactgroupmembers = array (); $row = $source_result->fetch_assoc(); $source_contactgroupmembers[] = $row);

        echo "\n";

        // do import
        foreach ($source_contactgroupmembers as $source_contactgroupmember) {
//            echo "\n";

            // get target contactgroup id
            $contactgroup_id_target = $this->getTargetContactgroupID($source_contactgroupmember['contactgroup_id']);
            if(empty($contactgroup_id_target))
                continue;

            // get target contact id
            $contact_id_target = $this->getTargetContactID($source_contactgroupmember['contact_id']);
            if(empty($contact_id_target))
                continue;

            // add contactgroupmember to DB
            $date = date($source_contactgroupmember['created']);

            $sql = "INSERT INTO contactgroupmembers (`contactgroup_id`,`contact_id`,`created`)
VALUES ('{$contactgroup_id_target}','{$contact_id_target}','{$date}');";

            if (mysqli_query($db, $sql)) {
//                $this->contactgroups[$key]['contactgroup_id_target'] = $db->insert_id;
                //echo $db->insert_id . "\n";
                echo "New contactgroupmember record created successfully: " . '$contactgroup_id_target: ' . $contactgroup_id_target . ' << $contact_id_target ' . $contact_id_target . "\n";
            } else {
                echo "Error: " . $sql . "\n" . mysqli_error($db);
            }

        }

        $this->closeConnection($db);

    }

    private function getTargetContactgroupID($source_contactgroupmember_contactgroup_id = null) {
        if($source_contactgroupmember_contactgroup_id === null)
            return null;

        $contactgroup_key = array_search($source_contactgroupmember_contactgroup_id, array_column($this->contactgroups, 'contactgroup_id'));

        if($contactgroup_key !== false) {

            $contactgroup_id_target = $this->contactgroups[$contactgroup_key]['contactgroup_id_target'];

        } else { // if none, search existing contactsgroups for name match

            // get name of contactgroup from source by contact group member's contactgroup_id
            $db_source = $this->setConnection($this->dbname_source);
            $sql = 'SELECT `name` FROM contactgroups WHERE contactgroup_id=\'' . $source_contactgroupmember_contactgroup_id . '\'';
            $source_result = $db_source->query($sql);
            for ($source_contactgroup = array (); $row = $source_result->fetch_assoc(); $source_contactgroup[] = $row);
            $this->closeConnection($db_source);
            if(empty($source_contactgroup[0]['name']))
                return null;
//            echo 'contactgroup name: ' . $source_contactgroup[0]['name'] . "\n";

            // get id of contactgroup from target by source contact group name
            $db_target = $this->setConnection($this->dbname);
            $sql = 'SELECT `contactgroup_id` FROM contactgroups WHERE `name`=\'' . $source_contactgroup[0]['name'] . '\'';
            $target_result = $db_target->query($sql);
            for ($target_contactgroup = array (); $row = $target_result->fetch_assoc(); $target_contactgroup[] = $row);
            $this->closeConnection($db_target);
            if(empty($target_contactgroup[0]['contactgroup_id']))
                return null;
            $contactgroup_id_target = $target_contactgroup[0]['contactgroup_id'];
//            echo '$contactgroup_id_target: ' . $contactgroup_id_target . "\n";

        }

        return $contactgroup_id_target;

    }

    private function getTargetContactID($source_contactgroupmember_contact_id = null) {
        if($source_contactgroupmember_contact_id === null)
            return null;

        $contact_key = array_search($source_contactgroupmember_contact_id, array_column($this->contacts, 'contact_id'));

        if($contact_key !== false) {

            $contact_id_target = $this->contacts[$contact_key]['contact_id_target'];

        } else { // if none, search existing contacts for words match

            // get words of contact from source by contact group member's contact_id
            $db_source = $this->setConnection($this->dbname_source);
            $sql = 'SELECT `words` FROM contacts WHERE contact_id=\'' . $source_contactgroupmember_contact_id . '\'';
            $source_result = $db_source->query($sql);
            for ($source_contact = array (); $row = $source_result->fetch_assoc(); $source_contact[] = $row);
//            echo 'contact words: ' . $source_contact[0]['words'] . "\n";
            $this->closeConnection($db_source);
            if(empty($source_contact[0]['words']))
                return null;

            // get id of contactgroup from target by source contact  words
            $db_target = $this->setConnection($this->dbname);
            $sql = 'SELECT `contact_id` FROM contacts WHERE words=\'' . $source_contact[0]['words'] . '\'';
            $target_result = $db_target->query($sql);
            for ($target_contact = array (); $row = $target_result->fetch_assoc(); $target_contact[] = $row);
            $this->closeConnection($db_target);
            if(empty($target_contact[0]['contact_id']))
                return null;
            $contact_id_target = $target_contact[0]['contact_id'];
//            echo '$contact_id_target: ' . $contact_id_target . "\n";

        }

        return $contact_id_target;

    }

    private function getContactgroupsmembers($db_name = null, $select = '*') {
        if ($db_name === null)
            return;

        $db = $this->setConnection($db_name);

        $sql = "SELECT {$select} FROM contactgroupmembers";
        $result = $db->query($sql);

        $this->closeConnection($db);

        return $result;
    }

    private function dropContactgroupmembers() {

        $db = $this->setConnection($this->dbname);

        $sql = "DELETE FROM contactgroupmembers";

        if (mysqli_query($db, $sql)) {
            echo "All contactgroupmember records deleted successfully" . "\n";
        } else {
            echo "Error: " . $sql . "\n" . mysqli_error($db);
        }

//        $sql = "ALTER TABLE contactgroups AUTO_INCREMENT = 1";
//        mysqli_query($db, $sql);

        $this->closeConnection($db);
    }

    private function importIdentities() {

        echo "\n";
        if (empty($this->users))
            return;

        foreach($this->users as $user) {
//            echo $user['user_id_source'] . ' >> ' . $user['user_id'] . ' - ' . $user['username'] . "\n";

            $target_result = $this->getIdentity($this->dbname, $user['user_id'], 'identity_id, user_id, email');
            for ($target_identity = array (); $row = $target_result->fetch_assoc(); $target_identity[] = $row);

            $source_result = $this->getIdentity($this->dbname_source, $user['user_id_source']);
            for ($source_identity = array (); $row = $source_result->fetch_assoc(); $source_identity[] = $row);

//            echo '$target_identity: ' . "\n" . print_r($target_identity[0],true) . "\n";
//            echo '$source_identity: ' . "\n" . print_r($source_identity[0],true) . "\n";

            // update existing record
            if($target_identity[0]['email'] === $source_identity[0]['email']) {

                $db = $this->setConnection($this->dbname);

                // identity_id, user_id, changed, del, standard, name, organization, email, reply-to, bcc, signature, html_signature
                $date = date($source_identity[0]['changed']);
                $del = $source_identity[0]['del'];
                $standard = $source_identity[0]['standard'];
                $name = $db->real_escape_string($source_identity[0]['name']);
                $organization = $db->real_escape_string($source_identity[0]['organization']);
                $reply_to = $db->real_escape_string($source_identity[0]['reply-to']);
                $bcc = $db->real_escape_string($source_identity[0]['bcc']);
                $signature = $db->real_escape_string($source_identity[0]['signature']);
                $html_signature = $source_identity[0]['html_signature'];

                $sql = "UPDATE identities SET `changed`='{$date}', `del`='{$del}', `standard`='{$standard}', `name`='{$name}', `organization`='{$organization}', `reply-to`='{$reply_to}', `bcc`='{$bcc}', `signature`='{$signature}', `html_signature`='{$html_signature}' WHERE identity_id={$target_identity[0]['identity_id']}";

                if (mysqli_query($db, $sql)) {
                    echo "Identity record updated successfully: " . $target_identity[0]['email'] . "\n";
                } else {
                    echo "Error: " . $sql . "\n" . mysqli_error($db);
                }

                $this->closeConnection($db);

            } else { // insert new record

                $db = $this->setConnection($this->dbname);

                // identity_id, user_id, changed, del, standard, name, organization, email, reply-to, bcc, signature, html_signature
                $user_id = $user['user_id'];
                $date = date($source_identity[0]['changed']);
                $del = $source_identity[0]['del'];
                $standard = $source_identity[0]['standard'];
                $name = $db->real_escape_string($source_identity[0]['name']);
                $organization = $db->real_escape_string($source_identity[0]['organization']);
                $email = $db->real_escape_string($source_identity[0]['email']);
                $reply_to = $db->real_escape_string($source_identity[0]['reply-to']);
                $bcc = $db->real_escape_string($source_identity[0]['bcc']);
                $signature = $db->real_escape_string($source_identity[0]['signature']);
                $html_signature = $source_identity[0]['html_signature'];

                $sql = "INSERT INTO identities (`user_id`, `changed`, `del`, `standard`, `name`, `organization`, `email`, `reply-to`, `bcc`, `signature`, `html_signature`)
VALUES ('{$user_id}', '{$date}', '{$del}', '{$standard}', '{$name}', '{$organization}', '{$email}', '{$reply_to}', '{$bcc}', '{$signature}', '{$html_signature}')";

                if (mysqli_query($db, $sql)) {
                    echo "New identity record created successfully: " . $email . "\n";
                } else {
                    echo "Error: " . $sql . "\n" . mysqli_error($db);
                }

                $this->closeConnection($db);

            }

        }



    }

    private function getIdentity($db_name = null, $user_id = null, $select = '*') {
        if ($db_name === null || $user_id === null)
            return;

        $db = $this->setConnection($db_name);

        $sql = "SELECT {$select} FROM identities WHERE user_id='" . $user_id . "'";
        $result = $db->query($sql);

        $this->closeConnection($db);

        return $result;
    }

}
$MigrateRoundcube = new MigrateRoundcube();
$MigrateRoundcube->init();

?>
