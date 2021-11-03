# migrate-roundcube for Plesk

Plesk migrator currently does not migrate address books of roundcube users. This script aims to solve this issue by 
* adding missing contacts,  
* add missing contactgroups, 
* match contacts with groups 
* and update the identities table (e.g. with the users' signatures).

## Usage   
* After Plesk migration create a dumb of `roundcubemail` database from the original Plesk and save it as `roundcubemail.sql`.  
* On the new Plesk: login with ssh and place the `migrate_roundcube.php` and  `roundcubemail.sql` in the same folder. 
* Replace the `username` and `password` of the db user with your credentials. Around line 50-60 within `setCredentials()`.
* Run script with `php migrate_roundcube.php`.

## FAQ
### Howto get the password for db user `admin`?
https://support.plesk.com/hc/en-us/articles/213375129-How-to-connect-to-a-MySQL-server-on-a-Plesk-for-Linux-server-using-a-MySQL-admin-password-in-plain-text 
Example:
```
# cat /etc/psa/.psa.shadow
$AES-128-CBC$ZmY/EEpy1+TwCNq5kalqSA==$Pd02kf4TTlpXdi/qyeo92w==
```
add the terminal output inside `migrate_roundcube.php` (approx. line 50).  
!!! Heads up: Make sure to use single quotes.
```
(...)
    private function setCredentials()
    {
        $this->host = "localhost";
        $this->db_user_name = "admin";
        $this->db_user_pass = '$AES-128-CBC$ZmY/EEpy1+TwCNq5kalqSA==$Pd02kf4TTlpXdi/qyeo92w==';
        $this->dbname = "roundcubemail";
        $this->dbname_source = "roundcubemail_import";
        $this->dump_file = "roundcubemail.sql";

    }
(...)
```

### Howto install `PHP CLI` and `mysqli`?
For Ubuntu 20
```
# apt install php7.4-cli
# apt-get install php-mysql
```
