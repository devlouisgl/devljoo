<?php
namespace MBP;

class Scheduler
{
    private static ?self $instance = null;

    private function __construct()
    {
        add_filter('cron_schedules', [$this, 'intervals']);
        add_action('mbp_run_cron_backup', [$this, 'exec_backup']);
    }

    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function intervals(array $schedules): array
    {
        $schedules['every_6h']  = ['interval' => 21600,  'display' => 'Cada 6 horas'];
        $schedules['every_12h'] = ['interval' => 43200,  'display' => 'Cada 12 horas'];
        $schedules['weekly']    = ['interval' => 604800, 'display' => 'Semanal'];
        $schedules['monthly']   = ['interval' => 2592000,'display' => 'Mensual'];
        return $schedules;
    }

    /** Reprogramar segun la frecuencia actual en opciones */
    public static function reschedule(): void
    {
        wp_clear_scheduled_hook('mbp_run_cron_backup');

        if (get_option('mbp_enabled', '1') !== '1') {
            return;
        }

        $freq = get_option('mbp_backup_frequency', 'daily');
        $time = get_option('mbp_backup_time', '02:00');
        [$h, $m] = array_pad(explode(':', $time), 2, 0);

        $ts = strtotime("today {$h}:{$m}:00");
        if ($ts < time()) {
            $ts = strtotime("tomorrow {$h}:{$m}:00");
        }

        $wp_sched = in_array($freq, ['hourly', 'twicedaily', 'daily'], true) ? $freq : 'daily';
        wp_schedule_event($ts, $wp_sched, 'mbp_run_cron_backup');
    }

    public function exec_backup(): void
    {
        if (get_transient('mbp_backup_lock')) {
            error_log('[MySQL Backup Pro] Backup bloqueado por transient');
            return;
        }
        set_transient('mbp_backup_lock', 1, HOUR_IN_SECONDS);

        try {
            $b = new Backup();
            $result = $b->run('automatic');
            if ($result['success']) {
                error_log('[MySQL Backup Pro] Backup automatico exitoso: ' . $result['filename']);
            } else {
                error_log('[MySQL Backup Pro] Backup automatico fallido: ' . ($result['message'] ?? 'Error desconocido'));
            }
        } catch (\Throwable $e) {
            error_log('[MySQL Backup Pro] Excepcion en backup automatico: ' . $e->getMessage());
        }

        delete_transient('mbp_backup_lock');
    }
}
