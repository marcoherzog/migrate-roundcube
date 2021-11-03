# migrate-roundcube for Plesk

Plesk migrator currently does not migrate address books of roundcube users.
* This script aims to solve this issue by adding missing contacts.  
* It will add missing contactgroups, 
* match contacts with groups 
* and update the identities table with the users' signatures.

Sorry for the short description.  

## Usage   
* After Plesk migration create a dumb of `roundcubemail` database from the original Plesk and save it as `roundcubemail.sql`.  
* On the new Plesk: login with ssh and place the `migrate_roundcube.php` and  `roundcubemail.sql` in the same folder. 
* replace the `username` and `password` of the db user with your credentials. Around line 50-60 within `setCredentials()`.
* run script with `php migrate_roundcube.php`.
