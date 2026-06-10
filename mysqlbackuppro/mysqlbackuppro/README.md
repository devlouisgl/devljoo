# MySQL Backup Pro

Professional MySQL backup plugin for Moodle 4.1+

## Features

- **Manual and Scheduled Backups**: Run backups on demand or schedule them automatically
- **S3 Compatible Storage**: Upload backups to Contabo, AWS, MinIO, Wasabi, DigitalOcean Spaces
- **Gzip Compression**: Compress SQL dumps to save storage space
- **Email Notifications**: Get notified when backups complete or fail
- **Retention Policy**: Automatically delete old backups beyond a set limit
- **S3 Explorer**: Browse, search, and manage your S3 bucket contents
- **Complete Database Dump**: Backs up all tables with full data using native PHP (no mysqldump dependency)
- **Encrypted Credentials**: S3 access and secret keys are encrypted at rest
- **Activity Logs**: Track all backup operations and events

## Requirements

- Moodle 4.1 or higher
- PHP 8.0 or higher
- MySQL database
- cURL extension (for S3 operations)
- write permissions to `$CFG->dataroot/mysql-backup-pro/`

## Installation

1. Download the plugin package
2. Extract it to `/local/mysqlbackuppro/` in your Moodle installation
3. Go to **Site Administration > Notifications** to install
4. The plugin will create its database tables automatically
5. Access via **Site Administration > Server > MySQL Backup Pro**

## Configuration

### Basic Settings

1. Go to **MySQL Backup Pro > Settings**
2. Enable automatic backups
3. Set frequency (hourly, twice daily, daily, weekly, monthly)
4. Set backup time
5. Configure retention count

### S3 Configuration (for cloud storage)

1. Enter your S3-compatible endpoint URL
2. Set region (use "default" for Contabo)
3. Enter bucket name
4. Provide access key and secret key
5. Enable path style (required for Contabo/MinIO)
6. Click **Test S3 Connection** to verify
7. Save settings

### Email Notifications

1. Enter a notification email address
2. Click **Send Test Email** to verify
3. Save settings

## Scheduled Task

The plugin registers a scheduled task that runs automatically based on your configured frequency. To verify it's working:

1. Go to **Site Administration > Server > Scheduled tasks**
2. Find **MySQL Backup Pro - Scheduled Backup**
3. Review the task settings

## Backup Storage

Local backups are stored in:
```
$CFG->dataroot/mysql-backup-pro/
```

S3 backups are stored with the structure:
```
{base_path}/{domain}/{year}/{month}/backup_{domain}_{timestamp}.sql.gz
```

## Security

- Directory protected with `.htaccess` and `index.php`
- S3 credentials encrypted using AES-256-CBC
- Access restricted to users with `local/mysqlbackuppro:manage` capability
- sesskey validation on all AJAX requests

## Troubleshooting

### Backups failing with JSON error
- Check PHP memory limit (recommend 512M+)
- Check max_execution_time (recommend 600s+)
- Verify write permissions to datadir

### S3 connection failing
- Verify endpoint URL includes https://
- For Contabo/MinIO: ensure Path Style is enabled
- Check firewall/cURL SSL settings
- Verify access credentials are correct

### Email not sending
- Verify Moodle email settings are configured
- Check spam folders
- Review server mail logs

## Changelog

### v2.2.1
- Fixed critical bug: `sql_compare_text_special` method not found in Moodle 4.1
- Fixed scheduled tasks API compatibility
- Improved error handling in AJAX handler
- Added fallback strings for JavaScript translations
- Enhanced JSON response validation
- Fixed checkbox value handling in settings save

### v2.2.0
- Initial stable release

## License

GPL v3 or later
