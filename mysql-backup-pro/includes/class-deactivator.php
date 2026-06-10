<?php
namespace MBP;

class Deactivator
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('mbp_run_cron_backup');
        delete_transient('mbp_backup_lock');
    }
}
