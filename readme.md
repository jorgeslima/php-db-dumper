# PHP DB Dumper
This is a little libary to automate the backup of databases with S3 and local storage support

This versions is made to run through CLI or CRON and it will keep only the 3 latest dumps of the database.

To make it work just clone this repository and run:

```
composer install
```

After that, you need to create a file called `.env` with the contents of the `.env.example` file and enter the needed settings in the `.env` file.

After this steps you just need to run `php DbDumper.php` on your terminal every time that you want a backup.

You can schedule this command into the CRON in order to make a fully automated backup tool
