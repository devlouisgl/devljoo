<?php
if (!defined('ABSPATH')) exit;

$users = get_users(['number' => -1, 'orderby' => 'ID', 'order' => 'ASC']);
$total = count($users);

// Conteo por rol
$role_counts = [];
foreach ($users as $u) {
    foreach ($u->roles as $r) {
        $role_counts[$r] = ($role_counts[$r] ?? 0) + 1;
    }
}
$role_labels = wp_roles()->get_names();
?>
<div class="wrap mbp-wrap">
    <h1>WP Users (Modo Test)</h1>

    <div class="mbp-notice mbp-notice-info">
        <strong>Modo de Pruebas:</strong> Esta tabla muestra los datos actuales de <code>wp_users</code>.
        Usala para verificar que los backups capturan correctamente toda la informacion.
        Total de usuarios: <strong><?php echo number_format($total); ?></strong>
    </div>

    <div class="mbp-grid">
        <div class="mbp-card mbp-card-blue">
            <span class="dashicons dashicons-groups"></span>
            <div><h2><?php echo number_format($total); ?></h2><p>Usuarios Totales</p></div>
        </div>
        <?php foreach ($role_counts as $role => $cnt) :
            $label = $role_labels[$role] ?? ucfirst($role);
            $colors = ['green','orange','blue','purple'];
            $color = $colors[array_search($role, array_keys($role_counts), true) % count($colors)];
        ?>
        <div class="mbp-card mbp-card-<?php echo $color; ?>">
            <span class="dashicons dashicons-admin-users"></span>
            <div><h2><?php echo number_format($cnt); ?></h2><p><?php echo esc_html($label); ?></p></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mbp-section">
        <h2>Lista de Usuarios</h2>
        <p>
            <input type="text" id="mbp-user-search" placeholder="Buscar usuario..." class="regular-text">
            <span style="color:#646970;margin-left:10px;">Mostrando <?php echo number_format($total); ?> usuarios</span>
        </p>

        <table class="widefat mbp-table" id="mbp-users-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Usuario</th>
                    <th>Email</th>
                    <th>Nombre</th>
                    <th>Roles</th>
                    <th>Posts</th>
                    <th>Registro</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u) :
                    $fn = get_user_meta($u->ID, 'first_name', true);
                    $ln = get_user_meta($u->ID, 'last_name', true);
                    $name = trim($fn . ' ' . $ln);
                ?>
                <tr>
                    <td><?php echo (int)$u->ID; ?></td>
                    <td><strong><?php echo esc_html($u->user_login); ?></strong></td>
                    <td><?php echo esc_html($u->user_email); ?></td>
                    <td><?php echo esc_html($name); ?></td>
                    <td>
                        <?php foreach ($u->roles as $r) {
                            $label = $role_labels[$r] ?? ucfirst($r);
                            echo '<span class="mbp-role mbp-role-' . esc_attr($r) . '">' . esc_html($label) . '</span>';
                        } ?>
                    </td>
                    <td><?php echo number_format(count_user_posts($u->ID)); ?></td>
                    <td><?php echo esc_html(wp_date('Y-m-d H:i', strtotime($u->user_registered))); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$users) : ?>
                <tr><td colspan="7" class="mbp-empty">No hay usuarios.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
