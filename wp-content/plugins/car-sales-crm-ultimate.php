<?php
/**
 * Plugin Name: Car Sales CRM - Ultimate Final Edition
 * Description: نظام إدارة مبيعات السيارات الشامل: طلبات، مندوبين، مستندات، عمولات، واتساب، تقارير، وأكثر — كل شيء في ملف واحد.
 * Version: 1.0.0
 * Author: Mohamed
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

define('CAR_CRM_VERSION', '1.0.0');

/**
 * دالة مساعدة لإرسال استجابة JSON مع بيانات الـ Request (مثل Laravel)
 */
function car_crm_send_json_success($data = []) {
    $response = [
        'success' => true,
        'data' => $data,
        'request_all' => $_POST // محاكاة Laravel $request->all()
    ];
    wp_send_json($response);
}

/**
 * دالة مساعدة لجلب كل الـ Meta Data لطلب معين بشكل نظيف (Key-Value)
 */
function car_crm_get_clean_meta($order_id) {
    $meta = get_post_meta($order_id);
    $clean = [];
    foreach ($meta as $key => $values) {
        $clean[$key] = is_array($values) ? $values[0] : $values;
    }
    return $clean;
}

/* =====================================================
   1. دالة الحالات
===================================================== */
function car_crm_get_statuses() {
    return [
        'new' => 'جديد',
        'inquiry' => 'استفسار',
        'inquiry_new' => 'استفسار جديد',
        'working' => 'جاري العمل',
        'working_urgent' => 'جاري العمل مهم',
        'docs_sent' => 'تم تسليم الأوراق',
        'approved' => 'مقبول',
        'completed' => 'مكتمل',
        'accredited' => 'تم التعميد',
        'no_answer' => 'لم يتم الرد',
        'unqualified' => 'غير مؤهل',
        'duplicate' => 'مكرر',
        'rejected' => 'مرفوض'
    ];
}

/* =====================================================
   2. تفعيل الإضافة + أدوار + Migration
===================================================== */
register_activation_hook(__FILE__, function() {
    if (!class_exists('WooCommerce')) {
        wp_die('هذه الإضافة تتطلب WooCommerce مفعل.');
    }

    global $wp_roles;
    if (!isset($wp_roles)) $wp_roles = new WP_Roles();

    $caps = [
        'read_car_crm',
        'view_crm_dashboard',
        'view_crm_personal_dashboard',
        'view_crm_all_orders',
        'view_crm_my_orders',
        'manage_crm_leads',
        'edit_own_crm_leads',
        'edit_all_crm_leads',
        'assign_crm_salesman',
        'view_crm_reports',
        'view_crm_analytics',
        'view_crm_salesmen_performance',
        'manage_crm_roles',
        'create_manual_sale',
        'manage_crm_dashboard_visibility'
    ];

    // إضافة الصلاحيات للإداري (جميع الصلاحيات)
    $admin = $wp_roles->get_role('administrator');
    if ($admin) {
        foreach ($caps as $cap) {
            $admin->add_cap($cap);
        }
    }

    // إضافة الصلاحيات ل shop_manager (جميع الصلاحيات)
    $shop_manager = $wp_roles->get_role('shop_manager');
    if ($shop_manager) {
        foreach ($caps as $cap) {
            $shop_manager->add_cap($cap);
        }
    }

    // إضافة الصلاحيات ل contributor (طلباتي فقط)
    $contributor = $wp_roles->get_role('contributor');
    if ($contributor) {
        $contributor->add_cap('read_car_crm');
        $contributor->add_cap('view_crm_my_orders');
        $contributor->add_cap('view_crm_personal_dashboard');
        $contributor->add_cap('edit_own_crm_leads');
        $contributor->add_cap('create_manual_sale');
    }

    // Migration لتوحيد meta keys
    global $wpdb;
    if (!get_option('car_crm_migrated_v4')) {
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_customer_bank' WHERE meta_key IN ('_billing_type', 'billing_type', '_billing_')");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_customer_job' WHERE meta_key IN ('_job', 'job')");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_customer_salary' WHERE meta_key IN ('billing_', '_billing_', '_billing_salary')");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_customer_nationality' WHERE meta_key IN ('_nationailty', '_nationality')");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_customer_city' WHERE meta_key = '_city'");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_customer_commitments' WHERE meta_key IN ('comemtment_', '_commitments')");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_order_type' WHERE meta_key = 'order_type'");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = 'crm_a' WHERE meta_key = '_crm_commission'");
        $wpdb->query("UPDATE {$wpdb->postmeta} SET meta_key = '_crm_staff_note' WHERE meta_key = 'staff_note'");
        update_option('car_crm_migrated_v4', true);
    }

    if (!get_option('car_crm_migrated_v8')) {
        global $wpdb;
        
        $meta_tables = [ $wpdb->prefix . 'postmeta' ];
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $meta_tables[] = $wpdb->prefix . 'wc_orders_meta';
        }

        foreach ($meta_tables as $table) {
            // التحقق من وجود الجدول قبل محاولة التحديث
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) continue;

            $id_field = (strpos($table, 'wc_orders_meta') !== false) ? 'order_id' : 'post_id';

            // 1. توحيد المفاتيح (Keys)
            $wpdb->query("UPDATE $table SET meta_key = '_crm_order_type' WHERE meta_key IN ('order_type', 'billing_type_field')");
            $wpdb->query("UPDATE $table SET meta_key = '_crm_custom_status' WHERE meta_key = 'crm_status'");
            
            // 2. توحيد قيم الأنواع (Values)
            $wpdb->query("UPDATE $table SET meta_value = 'company' WHERE meta_key = '_crm_order_type' AND meta_value IN ('company', 'Company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة')");
            $wpdb->query("UPDATE $table SET meta_value = 'individual' WHERE meta_key = '_crm_order_type' AND meta_value IN ('individual', 'Individual', 'fard', 'person', 'افراد', 'فرد')");

            // 3. توحيد قيم الحالات (Status Values)
            $statuses = car_crm_get_statuses();
            foreach ($statuses as $slug => $label) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET meta_value = %s WHERE meta_key = '_crm_custom_status' AND (LOWER(meta_value) = %s OR meta_value = %s)",
                    $slug,
                    strtolower($label),
                    $slug
                ));
            }
        }
        
        update_option('car_crm_migrated_v8', true);
    }

    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

/* =====================================================
   3. تحميل السكربتات والستايلات
===================================================== */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'car-crm') === false) return;

    wp_enqueue_media();
    wp_enqueue_style('bootstrap-rtl', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css');
    wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js', ['jquery']);
    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js');
    wp_enqueue_style('dashicons');

    // DataTables Core
    wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css');
    wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', ['jquery']);
    wp_enqueue_script('datatables-bootstrap', 'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js', ['datatables-js']);

    // Buttons Extension for Excel Export
    wp_enqueue_style('datatables-buttons-css', 'https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css');
    wp_enqueue_script('datatables-buttons', 'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js', ['datatables-js']);
    wp_enqueue_script('datatables-buttons-bootstrap', 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js', ['datatables-buttons']);
    wp_enqueue_script('datatables-buttons-html5', 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js', ['datatables-buttons']);
    wp_enqueue_script('jszip', 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js', ['jquery']);

    $is_manager_or_admin = current_user_can('manage_options') || current_user_can('assign_crm_salesman') || current_user_can('manage_crm_roles');

    wp_localize_script('bootstrap-js', 'CarCRM', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('car_crm_nonce'),
        'is_manager_or_admin' => $is_manager_or_admin,
        'current_user_id' => get_current_user_id()
    ]);

    wp_add_inline_style('bootstrap-rtl', '
        .crm-page { font-family: "Segoe UI", Tahoma; background: #f8f9fa; min-height: 100vh; padding: 2rem 0; }
        .crm-card { border-radius: 15px; border: none; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); background: #fff; transition: all 0.3s ease; }
        .crm-card:hover { box-shadow: 0 8px 25px rgba(0,0,0,0.12); transform: translateY(-2px); }
        .badge { padding: 0.5rem 0.75rem; border-radius: 20px; font-weight: 500; }
        .cursor-pointer { cursor: pointer; }

        /* Enhanced Table Styling */
        .table { border-collapse: separate; border-spacing: 0; }
        .table thead { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); }
        .table th {
            border: none;
            color: #495057;
            font-size: 0.9rem;
            font-weight: 600;
            padding: 1rem !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        .table tbody tr {
            border-bottom: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .table tbody tr:hover {
            background-color: #f8f9fa;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .table td {
            padding: 1rem !important;
            vertical-align: middle;
            border: none;
        }
        .table .btn-group { gap: 0.25rem; }
        .table .btn-sm { padding: 0.4rem 0.6rem; font-size: 0.85rem; }

        /* Customer Info Grid */
        .customer-info-grid .info-item { transition: all 0.3s ease; }
        .customer-info-grid .info-item:hover { transform: translateY(-3px); box-shadow: 0 6px 15px rgba(0,0,0,0.1) !important; }
        .info-label { letter-spacing: 0.5px; }
        .info-value { color: #212529; }

        /* Modal Styling */
        .modal-content { border-radius: 20px; border: none; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .modal-header { border-bottom: none; padding: 1.5rem; }
        .modal-body { padding: 2rem; }

        /* Button Styling */
        .btn { border-radius: 10px; padding: 0.6rem 1.2rem; transition: all 0.2s; }
        .btn-primary { background: #0d6efd; border: none; }
        .btn-primary:hover { background: #0b5ed7; transform: scale(1.02); }

        /* Status Cards for Dashboard */
        .status-card {
            border-radius: 10px;
            padding: 0.75rem 0.5rem;
            background: #fff;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            min-height: 95px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .status-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
            border-color: #0d6efd;
        }
        .status-card-title {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 0.25rem;
            text-transform: capitalize;
            line-height: 1.2;
        }
        .status-card-count {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0.25rem 0;
            line-height: 1;
        }
        .status-card-label {
            font-size: 0.7rem;
            color: #adb5bd;
            font-weight: 500;
        }

        /* Financial Summary Cards */
        .crm-card h6 {
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }
        .crm-card h2 {
            font-size: 2.2rem;
            line-height: 1.2;
            margin-top: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .crm-card small {
            font-size: 0.85rem;
            display: block;
            margin-top: 0.5rem;
        }

        /* DataTables Styling */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            font-family: "Segoe UI", Tahoma;
            font-size: 0.95rem;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 8px;
            margin: 0 2px;
            border: 1px solid #dee2e6;
            background: #fff;
            color: #0d6efd;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: #f8f9fa;
            border-color: #0d6efd;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: #fff !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* DataTables Search Box */
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            margin-left: 0.5rem;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }

        /* DataTables Length Menu */
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
        }

        /* DataTables Info */
        .dataTables_wrapper .dataTables_info {
            padding: 1rem 0;
            color: #6c757d;
        }

        /* DataTables Processing */
        .dataTables_wrapper .dataTables_processing {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            z-index: 9999;
        }

        .dataTables_wrapper .dataTables_processing.show {
            display: block;
        }

        /* Excel Button */
        .dt-buttons .btn-success {
            background-color: #198754 !important;
            border-color: #198754 !important;
            border-radius: 8px !important;
            padding: 0.5rem 1rem !important;
        }

        .dt-buttons .btn-success:hover {
            background-color: #157347 !important;
        }

        /* Table Header Sorting */
        .dataTables_wrapper thead th.sorting,
        .dataTables_wrapper thead th.sorting_asc,
        .dataTables_wrapper thead th.sorting_desc {
            cursor: pointer;
            background-repeat: no-repeat;
            background-position: center right;
            background-size: 16px;
            padding-right: 2rem !important;
        }

        .dataTables_wrapper thead th.sorting::after {
            content: " ";
            display: inline-block;
            width: 10px;
            height: 10px;
            margin-left: 5px;
            opacity: 0.3;
        }

        .dataTables_wrapper thead th.sorting_asc::after {
            opacity: 1;
        }

        .dataTables_wrapper thead th.sorting_desc::after {
            opacity: 1;
        }
    ');

    // Global AJAX Debugger (Laravel Style)
    wp_add_inline_script('bootstrap-js', '
        jQuery(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.data && settings.data.indexOf("car_crm") !== -1) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res.request_all) {
                        console.groupCollapsed("%c🚀 CRM Debug [Action]: " + (res.request_all.action || "Unknown"), "color: #0d6efd; font-weight: bold;");
                        console.log("%cRequest Payload (Laravel Style):", "color: #666; font-weight: bold;", res.request_all);
                        console.log("%cFull Table Data:", "color: #198754; font-weight: bold;", res.data);
                        
                        if (res.data && res.data.length > 0) {
                            console.groupCollapsed("%c🔍 Raw Database Meta (Sample Order #" + res.data[0].id + ")", "color: #6f42c1;");
                            console.log(res.data[0].database_raw_meta);
                            console.groupEnd();
                        }
                        
                        console.groupEnd();
                    }
                } catch(e) {}
            }
        });
    ');
});

/* =====================================================
   4. إنشاء القوائم في لوحة التحكم
===================================================== */
add_action('admin_menu', function() {
    // القائمة الرئيسية (يمكن للـ administrator و shop_manager و contributor الوصول)
    add_menu_page('CRM Dashboard', 'CRM Dashboard', 'read_car_crm', 'car-crm-dashboard', 'car_crm_dashboard_page', 'dashicons-dashboard', 56);

    // جميع الطلبات (administrator و shop_manager فقط)
    add_submenu_page('car-crm-dashboard', 'جميع الطلبات', 'جميع الطلبات', 'view_crm_all_orders', 'car-crm-all-orders', 'car_crm_all_orders_page');

    // طلباتي (الجميع)
    add_submenu_page('car-crm-dashboard', 'طلباتي', 'طلباتي', 'view_crm_my_orders', 'car-crm-my-orders', 'car_crm_my_orders_page');

    // التقارير المالية (administrator و shop_manager فقط)
    add_submenu_page('car-crm-dashboard', 'التقارير المالية', 'التقارير المالية', 'view_crm_reports', 'car-crm-financial-reports', 'car_crm_financial_reports_page');

    // أداء المناديب (administrator و shop_manager فقط)
    add_submenu_page('car-crm-dashboard', 'أداء المناديب', 'أداء المناديب', 'view_crm_salesmen_performance', 'car-crm-salesmen-performance', 'car_crm_salesmen_performance_page');

    // الرسوم البيانية (administrator و shop_manager فقط)
    add_submenu_page('car-crm-dashboard', 'الرسوم البيانية', 'الرسوم البيانية', 'view_crm_analytics', 'car-crm-analytics', 'car_crm_analytics_page');

    // Dashboard مخصص (الجميع)
    add_submenu_page('car-crm-dashboard', 'لوحتي الشخصية', 'لوحتي الشخصية', 'view_crm_personal_dashboard', 'car-crm-personal-dashboard', 'car_crm_personal_dashboard_page');

    // إدارة الصلاحيات (administrator فقط)
    add_submenu_page('car-crm-dashboard', 'إدارة الصلاحيات', 'إدارة الصلاحيات', 'manage_crm_roles', 'car-crm-roles', 'car_crm_roles_page');

    // إدارة رؤية الـ Dashboard (administrator و shop_manager فقط)
    add_submenu_page('car-crm-dashboard', 'إدارة رؤية Dashboards', 'إدارة رؤية Dashboards', 'manage_crm_dashboard_visibility', 'car-crm-dashboard-visibility', 'car_crm_dashboard_visibility_page');
});

/* =====================================================
   5. Dashboard بسيط
===================================================== */
function car_crm_dashboard_page() {
    // التحقق من الصلاحية
    if (!current_user_can('read_car_crm')) {
        wp_die('غير مصرح للوصول إلى هذه الصفحة');
    }

    $statuses = car_crm_get_statuses();
    $colors = [
        'new' => '#0d6efd',
        'inquiry' => '#0dcaf0',
        'inquiry_new' => '#00bcd4',
        'working' => '#ffc107',
        'working_urgent' => '#ff6b6b',
        'docs_sent' => '#6f42c1',
        'approved' => '#198754',
        'completed' => '#20c997',
        'accredited' => '#17a2b8',
        'no_answer' => '#868e96',
        'unqualified' => '#fd7e14',
        'duplicate' => '#e83e8c',
        'rejected' => '#dc3545'
    ];
    ?>
    <div class="crm-page container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <h1 class="fw-bold">📊 نظرة عامة على المبيعات</h1>
            <div class="text-muted"><?php echo date('Y-m-d'); ?></div>
        </div>

        <!-- حالات الطلبات - الأفراد والشركات -->
        <h3 class="fw-bold mb-4">📋 توزيع الطلبات حسب الحالة والنوع</h3>

        <!-- قسم الأفراد -->
        <div class="mb-5">
            <h5 class="fw-bold mb-3" style="color: #0dcaf0; font-size: 1.1rem;">👤 الأفراد</h5>
            <div class="row g-3" id="dashSummaryIndividuals">
                <!-- كارد اجمالي الأفراد -->
                <div class="col-lg-2 col-md-3 col-sm-6">
                    <div class="status-card" style="border-color: #0dcaf0; border-width: 3px; background: linear-gradient(135deg, #fff 0%, #0dcaf008 100%);">
                        <div class="status-card-title" style="font-size: 1rem;">👥 الإجمالي</div>
                        <div class="status-card-count" style="color: #0dcaf0;" id="total_individuals_count">...</div>
                        <div class="status-card-label">جميع الحالات</div>
                    </div>
                </div>

                <?php foreach ($statuses as $slug => $label): ?>
                    <?php $color = $colors[$slug] ?? '#6c757d'; ?>
                    <div class="col-lg-2 col-md-3 col-sm-6">
                        <div class="status-card" style="border-color: <?php echo $color; ?>40; background: linear-gradient(135deg, #fff 0%, <?php echo $color; ?>08 100%);">
                            <div class="status-card-title">
                                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $color; ?>;"></span>
                                <?php echo esc_html($label); ?>
                            </div>
                            <div class="status-card-count" style="color: <?php echo $color; ?>;" id="count_individual_<?php echo esc_attr($slug); ?>">...</div>
                            <div class="status-card-label">الأفراد</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- قسم الشركات -->
        <div class="mb-5">
            <h5 class="fw-bold mb-3" style="color: #198754; font-size: 1.1rem;">🏢 الشركات</h5>
            <div class="row g-3" id="dashSummaryCompanies">
                <!-- كارد اجمالي الشركات -->
                <div class="col-lg-2 col-md-3 col-sm-6">
                    <div class="status-card" style="border-color: #198754; border-width: 3px; background: linear-gradient(135deg, #fff 0%, #19875408 100%);">
                        <div class="status-card-title" style="font-size: 1rem;">🏢 الإجمالي</div>
                        <div class="status-card-count" style="color: #198754;" id="total_companies_count">...</div>
                        <div class="status-card-label">جميع الحالات</div>
                    </div>
                </div>

                <?php foreach ($statuses as $slug => $label): ?>
                    <?php $color = $colors[$slug] ?? '#6c757d'; ?>
                    <div class="col-lg-2 col-md-3 col-sm-6">
                        <div class="status-card" style="border-color: <?php echo $color; ?>40; background: linear-gradient(135deg, #fff 0%, <?php echo $color; ?>08 100%);">
                            <div class="status-card-title">
                                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $color; ?>;"></span>
                                <?php echo esc_html($label); ?>
                            </div>
                            <div class="status-card-count" style="color: <?php echo $color; ?>;" id="count_company_<?php echo esc_attr($slug); ?>">...</div>
                            <div class="status-card-label">الشركات</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- الرسوم البيانية -->
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="crm-card p-4 h-100">
                    <h5 class="fw-bold mb-4">👥 أداء المناديب (عمليات البيع الناجحة)</h5>
                    <div style="height: 350px;">
                        <canvas id="salesmanChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="crm-card p-4 h-100">
                    <h5 class="fw-bold mb-4">📈 توزيع حالات الطلبات</h5>
                    <div style="height: 350px;">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $.post(CarCRM.ajax_url, {
            action: 'car_crm_get_dashboard_stats',
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                const data = res.data;

                // تحديث بطاقات الحالات (الأفراد والشركات)
                if (data.status_type_counts && Array.isArray(data.status_type_counts)) {
                    let total_individuals = 0;
                    let total_companies = 0;

                    data.status_type_counts.forEach(item => {
                        $('#count_individual_' + item.status).text(item.individual_count || 0);
                        $('#count_company_' + item.status).text(item.company_count || 0);
                        total_individuals += item.individual_count || 0;
                        total_companies += item.company_count || 0;
                    });

                    // تحديث الإجماليات
                    $('#total_individuals_count').text(total_individuals);
                    $('#total_companies_count').text(total_companies);
                }

                let total = 0;
                data.status_dist.forEach(s => {
                    total += s.count;
                });

                const distLabels = data.status_dist.map(s => s.label);
                const distCounts = data.status_dist.map(s => s.count);

                // الرسم البياني لتوزيع الحالات
                new Chart(document.getElementById('statusChart'), {
                    type: 'doughnut',
                    data: {
                        labels: distLabels,
                        datasets: [{
                            data: distCounts,
                            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#6c757d', '#dc3545', '#6610f2', '#fd7e14', '#20c997', '#0dcaf0', '#868e96', '#e83e8c', '#17a2b8']
                        }]
                    },
                    options: { maintainAspectRatio: false }
                });

                // الرسم البياني لأداء المناديب
                const smLabels = data.salesman_perf.map(s => s.name);
                const smCounts = data.salesman_perf.map(s => s.count);

                new Chart(document.getElementById('salesmanChart'), {
                    type: 'bar',
                    data: {
                        labels: smLabels,
                        datasets: [{
                            label: 'عدد المبيعات المكتملة',
                            data: smCounts,
                            backgroundColor: '#0d6efd',
                            borderRadius: 10
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                    }
                });
            }
        });
    });
    </script>
    <?php
}

/* =====================================================
   5.5 Dashboard مخصص لكل مستخدم (Personal Dashboard)
===================================================== */
function car_crm_personal_dashboard_page() {
    // التحقق من الصلاحية
    if (!current_user_can('view_crm_personal_dashboard')) {
        wp_die('غير مصرح للوصول إلى لوحتك الشخصية');
    }

    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();

    // التحقق إذا كان Dashboard مفعل لهذا المستخدم
    $dashboard_enabled = get_user_meta($user_id, '_crm_personal_dashboard_enabled', true);
    if ($dashboard_enabled === '') {
        // القيمة الافتراضية: مفعل للجميع
        $dashboard_enabled = 'yes';
    }

    if ($dashboard_enabled !== 'yes') {
        wp_die('لم يتم تفعيل لوحتك الشخصية. يرجى التواصل مع الإدارة.');
    }

    $statuses = car_crm_get_statuses();
    ?>
    <div class="crm-page container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h1 class="fw-bold">👤 لوحتي الشخصية</h1>
                <p class="text-muted mt-2">مرحباً بك، <strong><?php echo esc_html($current_user->display_name); ?></strong></p>
            </div>
            <div class="text-muted"><?php echo date('Y-m-d'); ?></div>
        </div>

        <!-- معلومات سريعة -->
        <div class="row g-3 mb-5">
            <div class="col-lg-3 col-md-6">
                <div class="crm-card p-4 text-center bg-success bg-opacity-10 border-success">
                    <h4 class="text-success mb-2">✅ المبيعات المكتملة</h4>
                    <div style="font-size: 2.5rem; font-weight: bold; color: #198754;" id="completed_count">...</div>
                    <small class="text-muted">عدد الطلبات المكتملة</small>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="crm-card p-4 text-center bg-warning bg-opacity-10 border-warning">
                    <h4 class="text-warning mb-2">⏳ قيد المعالجة</h4>
                    <div style="font-size: 2.5rem; font-weight: bold; color: #ffc107;" id="working_count">...</div>
                    <small class="text-muted">الطلبات قيد العمل</small>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="crm-card p-4 text-center bg-info bg-opacity-10 border-info">
                    <h4 class="text-info mb-2">📋 المعلقة</h4>
                    <div style="font-size: 2.5rem; font-weight: bold; color: #0dcaf0;" id="pending_count">...</div>
                    <small class="text-muted">الطلبات المعلقة</small>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="crm-card p-4 text-center bg-danger bg-opacity-10 border-danger">
                    <h4 class="text-danger mb-2">❌ المرفوضة</h4>
                    <div style="font-size: 2.5rem; font-weight: bold; color: #dc3545;" id="rejected_count">...</div>
                    <small class="text-muted">الطلبات المرفوضة</small>
                </div>
            </div>
        </div>

        <!-- توزيع الحالات -->
        <div class="row g-4 mb-5">
            <div class="col-lg-6">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">📊 توزيع حالات طلباتك</h5>
                    <div style="height: 300px;">
                        <canvas id="userStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">📈 تفاصيل الحالات</h5>
                    <div id="statusDetailsTable" style="max-height: 350px; overflow-y: auto;">
                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                            <span class="visually-hidden">جاري التحميل...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- آخر الطلبات -->
        <div class="row g-4">
            <div class="col-lg-12">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">📋 آخر 10 طلبات لك</h5>
                    <div class="table-responsive">
                        <table class="table table-hover" id="recentOrdersTable">
                            <thead class="table-light">
                                <tr>
                                    <th>رقم الطلب</th>
                                    <th>العميل</th>
                                    <th>الحالة</th>
                                    <th>المبلغ</th>
                                    <th>التاريخ</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody id="recentOrdersBody">
                                <tr>
                                    <td colspan="6" class="text-center text-muted">جاري التحميل...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $.post(CarCRM.ajax_url, {
            action: 'car_crm_get_personal_dashboard_stats',
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                const data = res.data;

                // تحديث الأرقام الرئيسية
                $('#completed_count').text(data.completed_count || 0);
                $('#working_count').text(data.working_count || 0);
                $('#pending_count').text(data.pending_count || 0);
                $('#rejected_count').text(data.rejected_count || 0);

                // الرسم البياني لتوزيع الحالات
                if (data.status_dist && data.status_dist.length > 0) {
                    const labels = data.status_dist.map(s => s.label);
                    const counts = data.status_dist.map(s => s.count);
                    const colors = [
                        '#0d6efd', '#0dcaf0', '#00bcd4', '#ffc107', '#ff6b6b', '#6f42c1',
                        '#198754', '#20c997', '#17a2b8', '#868e96', '#fd7e14', '#e83e8c', '#dc3545'
                    ];

                    new Chart(document.getElementById('userStatusChart'), {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: counts,
                                backgroundColor: colors.slice(0, labels.length),
                                borderColor: '#fff',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        font: { family: "'Segoe UI', Tahoma" },
                                        padding: 15
                                    }
                                }
                            }
                        }
                    });
                }

                // جدول تفاصيل الحالات
                if (data.status_details && data.status_details.length > 0) {
                    let html = '<table class="table table-sm table-borderless">';
                    data.status_details.forEach(item => {
                        html += '<tr>';
                        html += '<td><strong>' + item.label + '</strong></td>';
                        html += '<td class="text-end"><span class="badge bg-primary">' + item.count + '</span></td>';
                        html += '</tr>';
                    });
                    html += '</table>';
                    $('#statusDetailsTable').html(html);
                }

                // جدول آخر الطلبات
                if (data.recent_orders && data.recent_orders.length > 0) {
                    let tbody = '';
                    data.recent_orders.forEach(order => {
                        tbody += '<tr>';
                        tbody += '<td><strong>#' + order.id + '</strong></td>';
                        tbody += '<td>' + order.customer + '</td>';
                        tbody += '<td><span class="badge bg-primary">' + order.status + '</span></td>';
                        tbody += '<td>' + order.total + '</td>';
                        tbody += '<td>' + order.date + '</td>';
                        tbody += '<td>';
                        tbody += '<a href="#" class="btn btn-sm btn-primary" onclick="showOrderDetails(' + order.id + '); return false;">عرض</a>';
                        tbody += '</td>';
                        tbody += '</tr>';
                    });
                    $('#recentOrdersBody').html(tbody);
                }
            }
        });
    });

    function showOrderDetails(orderId) {
        // فتح موديل تفاصيل الطلب أو التوجه لصفحة الطلب
        alert('سيتم عرض تفاصيل الطلب #' + orderId);
    }
    </script>
    <?php
}

/* =====================================================
   5.6 صفحة إدارة رؤية Dashboards
===================================================== */
function car_crm_dashboard_visibility_page() {
    if (!current_user_can('manage_crm_dashboard_visibility')) {
        wp_die('غير مصرح لإدارة رؤية Dashboards');
    }

    // معالجة الحفظ
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_visibility'])) {
        check_admin_referer('car_crm_visibility_nonce', 'nonce');

        $users = get_users();
        foreach ($users as $user) {
            $enabled = isset($_POST['user_' . $user->ID]) ? 'yes' : 'no';
            update_user_meta($user->ID, '_crm_personal_dashboard_enabled', $enabled);
        }

        echo '<div class="notice notice-success"><p>تم حفظ الإعدادات بنجاح!</p></div>';
    }

    $users = get_users(['role__in' => ['contributor', 'editor', 'car_crm_salesman']]);
    $statuses = car_crm_get_statuses();
    ?>
    <div class="crm-page container-fluid">
        <h1 class="fw-bold mb-4">🔐 إدارة رؤية Dashboards</h1>

        <div class="crm-card p-4">
            <h5 class="fw-bold mb-4">اختر من يمكنهم رؤية لوحتهم الشخصية</h5>

            <form method="POST">
                <?php wp_nonce_field('car_crm_visibility_nonce', 'nonce'); ?>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th width="50px">
                                    <input type="checkbox" id="checkAll" onchange="document.querySelectorAll('input[type=checkbox][name^=user_]').forEach(el => el.checked = this.checked);">
                                </th>
                                <th>اسم المستخدم</th>
                                <th>البريد الإلكتروني</th>
                                <th>الدور</th>
                                <th>الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user):
                                $enabled = get_user_meta($user->ID, '_crm_personal_dashboard_enabled', true);
                                if ($enabled === '') $enabled = 'yes'; // القيمة الافتراضية
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="user_<?php echo $user->ID; ?>" value="1" <?php checked($enabled, 'yes'); ?>>
                                </td>
                                <td><strong><?php echo esc_html($user->display_name); ?></strong></td>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td>
                                    <?php
                                    $user_obj = new WP_User($user->ID);
                                    $role = !empty($user_obj->roles) ? $user_obj->roles[0] : 'N/A';
                                    echo esc_html(ucfirst($role));
                                    ?>
                                </td>
                                <td>
                                    <?php if ($enabled === 'yes'): ?>
                                        <span class="badge bg-success">✅ مفعل</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">❌ معطل</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="save_visibility" class="btn btn-primary mt-3">
                    <i class="dashicons dashicons-yes"></i> حفظ الإعدادات
                </button>
            </form>
        </div>
    </div>
    <?php
}

/* =====================================================
   6. صفحات الطلبات
===================================================== */
function car_crm_all_orders_page() {
    car_crm_render_orders_page('all');
}

function car_crm_my_orders_page() {
    car_crm_render_orders_page('my');
}

function car_crm_render_orders_page($context = 'all') {
    // التحقق من الصلاحيات حسب السياق
    if ($context === 'all') {
        if (!current_user_can('view_crm_all_orders')) {
            wp_die('غير مصرح للوصول إلى جميع الطلبات');
        }
    } elseif ($context === 'my') {
        if (!current_user_can('view_crm_my_orders')) {
            wp_die('غير مصرح للوصول إلى طلباتك');
        }
    }

    $statuses = car_crm_get_statuses();
    $is_manager_or_admin = current_user_can('manage_options') || current_user_can('view_crm_all_orders');
    $show_sidebar = true; // Show sidebar for both 'all' and 'my' pages 

    // عدادات للمدير/الأدمن (تحسين الأداء: استعلام SQL مباشر بدلاً من تحميل كافة الطلبات)
    $individual_statuses = $company_statuses = [];
    foreach ($statuses as $slug => $label) {
        $individual_statuses[$slug] = ['label' => $label, 'count' => 0];
        $company_statuses[$slug] = ['label' => $label, 'count' => 0];
    }
    $total_individuals = $total_companies = 0;

    if ($show_sidebar && current_user_can('read_car_crm')) {
        global $wpdb;
        
        $using_hpos = false;
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
            $using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }

        $order_table = $wpdb->prefix . ($using_hpos ? 'wc_orders' : 'posts');
        $meta_table  = $wpdb->prefix . ($using_hpos ? 'wc_orders_meta' : 'postmeta');
        $id_col      = $using_hpos ? 'id' : 'ID';
        $type_col    = $using_hpos ? 'type' : 'post_type';
        $status_col  = $using_hpos ? 'status' : 'post_status';
        $meta_id_col = $using_hpos ? 'order_id' : 'post_id';

        // تصحيح اسم جدول الميتا في حال كان مختلفاً في HPOS
        if ($using_hpos && $wpdb->get_var("SHOW TABLES LIKE '$meta_table'") !== $meta_table) {
            $meta_table = $wpdb->prefix . 'wc_order_meta';
        }

        $current_user_id = get_current_user_id();
        $salesman_scope_sql = "";
        
        // IF context is 'my', strictly scope to the current user
        if ($context === 'my') {
            $salesman_scope_sql = $wpdb->prepare("
                JOIN {$meta_table} sm ON o.{$id_col} = sm.{$meta_id_col} 
                AND sm.meta_key = '_crm_salesman_id' AND sm.meta_value = %d
            ", $current_user_id);
        }

        $query = "
            SELECT 
                o.{$id_col} as ID,
                MAX(CASE WHEN om.meta_key = '_crm_custom_status' THEN om.meta_value END) as current_status,
                MAX(CASE WHEN om.meta_key = 'crm_status' THEN om.meta_value END) as old_status,
                MAX(CASE WHEN om.meta_key = '_crm_order_type' THEN om.meta_value END) as current_type,
                MAX(CASE WHEN om.meta_key = 'order_type' THEN om.meta_value END) as old_type
            FROM {$order_table} o
            {$salesman_scope_sql}
            LEFT JOIN {$meta_table} om ON o.{$id_col} = om.{$meta_id_col} 
                AND om.meta_key IN ('_crm_custom_status', 'crm_status', '_crm_order_type', 'order_type')
            WHERE o.{$type_col} = 'shop_order' 
            AND o.{$status_col} NOT IN ('trash', 'auto-draft')
            GROUP BY o.{$id_col}
        ";
        
        $results = $wpdb->get_results($query);

        // إذا فشل الاستعلام تماماً، نحاول العودة للنمط التقليدي كخيار أخير
        if (empty($results) && $using_hpos) {
            $order_table = $wpdb->prefix . 'posts';
            $meta_table  = $wpdb->prefix . 'postmeta';
            $id_col      = 'ID';
            $type_col    = 'post_type';
            $status_col  = 'post_status';
            $meta_id_col = 'post_id';
            
            $results = $wpdb->get_results("
                SELECT 
                    o.{$id_col} as ID,
                    MAX(CASE WHEN om.meta_key = '_crm_custom_status' THEN om.meta_value END) as current_status,
                    MAX(CASE WHEN om.meta_key = 'crm_status' THEN om.meta_value END) as old_status,
                    MAX(CASE WHEN om.meta_key = '_crm_order_type' THEN om.meta_value END) as current_type,
                    MAX(CASE WHEN om.meta_key = 'order_type' THEN om.meta_value END) as old_type
                FROM {$order_table} o
                {$salesman_scope_sql}
                LEFT JOIN {$meta_table} om ON o.{$id_col} = om.{$meta_id_col} 
                    AND om.meta_key IN ('_crm_custom_status', 'crm_status', '_crm_order_type', 'order_type')
                WHERE o.{$type_col} = 'shop_order' 
                AND o.{$status_col} NOT IN ('trash', 'auto-draft')
                GROUP BY o.{$id_col}
            ");
        }

        if (!empty($results)) {
            foreach ($results as $res) {
                $s_raw = $res->current_status ?: $res->old_status ?: 'new';
                $s_key = 'new';
                
                if (isset($statuses[$s_raw])) {
                    $s_key = $s_raw; // it is a slug
                } else {
                    $found_slug = array_search($s_raw, $statuses);
                    if ($found_slug !== false) {
                        $s_key = $found_slug;
                    }
                }

                if (!isset($individual_statuses[$s_key])) $s_key = 'new';
                
                $raw_t_new = strtolower($res->current_type ?: '');
                $raw_t_old = strtolower($res->old_type ?: '');
                $company_synonyms = ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'];
                
                if (in_array($raw_t_new, $company_synonyms) || in_array($raw_t_old, $company_synonyms)) {
                    $t = 'company';
                } else {
                    $t = 'individual';
                }
                
                if ($t === 'company') {
                    $company_statuses[$s_key]['count']++;
                    $total_companies++;
                } else {
                    $individual_statuses[$s_key]['count']++;
                    $total_individuals++;
                }
            }
        }
    }
    ?>
    
    
    <script>
jQuery.post(CarCRM.ajax_url, {
    action: 'car_crm_debug_meta',
    order_id: 35051, // ← غيره لرقم طلب حقيقي عندك
    nonce: CarCRM.nonce
}, function(res) {
    if (res.success) {
        console.log('%c🔍 CRM Meta Data (Order #' + 35051 + ')', 'color: purple; font-weight: bold;', res.data);
    }
});
</script>
    <div class="crm-page container-fluid" data-page-context="<?php echo esc_attr($context); ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><?php echo $show_sidebar ? 'جميع الطلبات' : 'طلباتي'; ?></h1>
            <?php if (current_user_can('create_manual_sale')): ?>
            <button class="btn btn-primary shadow-sm" onclick="carCrmOpenManualOrder()">
                <i class="dashicons dashicons-plus-alt2"></i> إضافة عملية بيع يدوية
            </button>
            <?php endif; ?>
        </div>

        <?php if ($show_sidebar): ?>
        <div class="row g-4">
            <div class="col-lg-2">
                <!-- الأفراد -->
                <div class="crm-card mb-3">
                    <div class="card-header bg-info text-white fw-bold p-3 d-flex justify-content-between">
                        <span><i class="dashicons dashicons-admin-users"></i> الأفراد</span>
                        <span class="badge bg-white text-info"><?php echo $total_individuals; ?></span>
                    </div>
                    <div class="card-body p-0">
                        <label class="list-group-item p-3 cursor-pointer border-0 d-flex justify-content-between">
                            <span><input type="radio" class="form-check-input order-filter" name="filterType" value="individual"> جميع الأفراد</span>
                            <span class="badge bg-light text-dark"><?php echo $total_individuals; ?></span>
                        </label>
                        <?php foreach ($individual_statuses as $key => $st): ?>
                        <label class="list-group-item p-2 cursor-pointer border-0 d-flex justify-content-between">
                            <span><input type="radio" class="form-check-input order-filter" name="filterStatus" value="<?php echo $key; ?>" data-type="individual"> <?php echo $st['label']; ?></span>
                            <span class="badge bg-light text-dark"><?php echo $st['count']; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- الشركات -->
                <div class="crm-card mb-3">
                    <div class="card-header bg-success text-white fw-bold p-3 d-flex justify-content-between">
                        <span><i class="dashicons dashicons-building"></i> الشركات</span>
                        <span class="badge bg-white text-success"><?php echo $total_companies; ?></span>
                    </div>
                    <div class="card-body p-0">
                        <label class="list-group-item p-3 cursor-pointer border-0 d-flex justify-content-between">
                            <span><input type="radio" class="form-check-input order-filter" name="filterType" value="company"> جميع الشركات</span>
                            <span class="badge bg-light text-dark"><?php echo $total_companies; ?></span>
                        </label>
                        <?php foreach ($company_statuses as $key => $st): ?>
                        <label class="list-group-item p-2 cursor-pointer border-0 d-flex justify-content-between">
                            <span><input type="radio" class="form-check-input order-filter" name="filterStatus" value="<?php echo $key; ?>" data-type="company"> <?php echo $st['label']; ?></span>
                            <span class="badge bg-light text-dark"><?php echo $st['count']; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- الكل -->
                <div class="crm-card">
                    <div class="card-header bg-secondary text-white fw-bold p-3">
                        <i class="dashicons dashicons-list-view"></i> الكل
                    </div>
                    <div class="card-body p-0">
                        <label class="list-group-item p-3 cursor-pointer border-0 d-flex justify-content-between">
                            <span><input type="radio" class="form-check-input order-filter" name="filterType" value="all" checked> جميع الطلبات</span>
                            <span class="badge bg-light text-dark"><?php echo $total_individuals + $total_companies; ?></span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="col-lg-10">
        <?php else: ?>
            <div class="col-12">
        <?php endif; ?>

                <div class="crm-card">
                    <div class="table-responsive">
                        <table id="ordersTable" class="table table-hover align-middle mb-0">
                            <thead class="table-light fw-bold">
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php car_crm_render_all_modals(); ?>

    <script>
    function buildActionButtons(row) {
        const isManager = CarCRM.is_manager_or_admin;
        const pageContext = '<?php echo $context; ?>';
        let html = '<div class="btn-group btn-group-sm">';

        html += '<button class="btn btn-sm btn-outline-primary" title="بيانات العميل" data-order-id="' + row.id + '" onclick="window.carCrmOpenCustomer(' + row.id + ')"><i class="dashicons dashicons-businessman"></i></button>';

        html += '<button class="btn btn-sm btn-outline-info" title="بنود الطلب" onclick="window.carCrmOpenItems(' + row.id + ')"><i class="dashicons dashicons-car"></i></button>';

        html += '<button class="btn btn-sm btn-outline-dark" title="المرفقات" onclick="window.carCrmOpenDocs(' + row.id + ')"><i class="dashicons dashicons-media-document"></i></button>';

        html += '<button class="btn btn-sm btn-outline-secondary" title="تغيير الحالة" onclick="window.carCrmOpenStatus(' + row.id + ', \'' + row.status_key + '\')"><i class="dashicons dashicons-update"></i></button>';

        html += '<button class="btn btn-sm btn-outline-warning" title="الملاحظات" onclick="window.carCrmOpenNotes(' + row.id + ')"><i class="dashicons dashicons-admin-comments"></i></button>';

        if (isManager && pageContext === 'all') {
            html += '<button class="btn btn-sm btn-outline-info" title="العمولة" onclick="window.carCrmOpenCommission(' + row.id + ')"><i class="dashicons dashicons-money-alt"></i></button>';

            html += '<button class="btn btn-sm btn-outline-secondary" title="إسناد مندوب" onclick="window.carCrmAssignSalesman(' + row.id + ')"><i class="dashicons dashicons-groups"></i></button>';

            html += '<button class="btn btn-sm btn-outline-danger" title="حذف الطلب" onclick="window.carCrmDeleteOrder(' + row.id + ')"><i class="dashicons dashicons-trash"></i></button>';
        }

        html += '<a href="https://wa.me/' + row.phone_clean + '?text=مرحباً ' + encodeURIComponent(row.customer) + '، رقم طلبك هو #' + row.id + '. شكراً لثقتك!" class="btn btn-sm btn-outline-success" target="_blank" title="واتساب"><i class="dashicons dashicons-whatsapp"></i></a>';

        html += '</div>';
        return html;
    }

    jQuery(document).ready(function($) {
        window.ordersTable = $('#ordersTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: CarCRM.ajax_url,
                type: 'POST',
                data: function(d) {
                    d.action = 'car_crm_fetch_orders';
                    d.nonce = CarCRM.nonce;
                    d.page_context = '<?php echo $context; ?>';

                    if (<?php echo $show_sidebar ? 'true' : 'false'; ?>) {
                        d.type = $('input[name="filterType"]:checked').val() || 'all';
                        d.status = $('input[name="filterStatus"]:checked').val() || 'all';
                    }

                    if (!CarCRM.is_manager_or_admin) {
                        d.salesman_id = CarCRM.current_user_id;
                    }

                    console.log('%cDataTables AJAX Request:', 'color: blue; font-weight: bold;', d);
                }
            },
            columns: [
                { data: 'id', title: 'رقم', orderable: true },
                { data: 'customer', title: 'العميل', orderable: false },
                {
                    data: 'phone',
                    title: 'الهاتف',
                    orderable: false,
                    render: function(data) {
                        return '<a href="tel:' + data + '">' + data + '</a>';
                    }
                },
                {
                    data: 'type_badge',
                    title: 'النوع',
                    orderable: false,
                    className: 'text-center'
                },
                {
                    data: 'salesman_badge',
                    title: 'المندوب',
                    orderable: false,
                    className: 'text-center'
                },
                {
                    data: 'status',
                    title: 'الحالة',
                    orderable: false,
                    className: 'text-center',
                    render: function(data) {
                        return '<span class="badge bg-primary">' + data + '</span>';
                    }
                },
                <?php if ($context === 'all'): ?>
                {
                    data: 'commission',
                    title: 'العمولة',
                    orderable: false,
                    className: 'col-commission text-center'
                },
                <?php endif; ?>
                {
                    data: 'staff_note',
                    title: 'ملاحظات',
                    orderable: false,
                    className: 'text-center',
                    render: function(data) {
                        return '<small class="text-muted">' + data + '</small>';
                    }
                },
                {
                    data: 'order_source',
                    title: 'المصدر',
                    orderable: false,
                    className: 'text-center'
                },
                {
                    data: null,
                    title: 'الإجراءات',
                    orderable: false,
                    className: 'text-center',
                    render: function(data, type, row) {
                        return buildActionButtons(row);
                    }
                }
            ],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"B>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            buttons: [
                {
                    extend: 'excelHtml5',
                    text: '<i class="dashicons dashicons-download"></i> تصدير Excel',
                    className: 'btn btn-success btn-sm mb-3',
                    title: 'طلبات السيارات',
                    exportOptions: {
                        columns: ':not(:last-child)',
                        format: {
                            body: function(data, row, column, node) {
                                return $(data).text() || data;
                            }
                        }
                    }
                }
            ],
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/ar.json',
                info: "عرض _START_ إلى _END_ من إجمالي _TOTAL_ طلب",
                infoFiltered: "(مفلتر من _MAX_ طلب إجمالي)"
            },
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
            order: [[0, 'desc']],
            responsive: true
        });

        $(document).on('change', '.order-filter', function() {
            const name = $(this).attr('name');

            if (name === 'filterStatus') {
                const type = $(this).data('type');
                if (type) {
                    $('input[name="filterType"][value="' + type + '"]').prop('checked', true);
                }
            }

            if (name === 'filterType') {
                $('input[name="filterStatus"]').prop('checked', false);
            }

            window.ordersTable.ajax.reload();
        });
    });
    </script>
<?php
}

/* =====================================================
   7. دالة عدادات الحالات
===================================================== */
function car_crm_count_status_type($status, $type) {
    return 0; // دالة أصبحت غير مستخدمة ولكن نبقيها منعاً للأخطاء
}

/* =====================================================
   8. جلب الطلبات عبر AJAX
===================================================== */
add_action('wp_ajax_car_crm_fetch_orders', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');

    // Increase timeout for slow queries
    set_time_limit(120);

    // Disable query limits and errors during processing
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    global $wpdb;

    // Debug: Log the incoming request
    error_log('=== CAR_CRM FETCH ORDERS START ===');
    error_log('POST Data: ' . json_encode($_POST));

    $context = sanitize_text_field($_POST['page_context'] ?? 'all');

    // التحقق من الصلاحيات
    if ($context === 'all') {
        if (!current_user_can('view_crm_all_orders')) {
            wp_send_json_error('غير مصرح للوصول إلى جميع الطلبات');
        }
    } elseif ($context === 'my') {
        if (!current_user_can('view_crm_my_orders')) {
            wp_send_json_error('غير مصرح للوصول إلى طلباتك');
        }
    } else {
        if (!current_user_can('read_car_crm')) {
            wp_send_json_error('غير مصرح');
        }
    }

    // Extract DataTables parameters
    $draw = intval($_POST['draw'] ?? 1);
    $start = intval($_POST['start'] ?? 0);
    $length = intval($_POST['length'] ?? 25);

    // Fix search value extraction - DataTables sends search as array with 'value' key
    $search_value = '';
    if (!empty($_POST['search'])) {
        if (is_array($_POST['search']) && isset($_POST['search']['value'])) {
            $search_value = sanitize_text_field($_POST['search']['value']);
        } elseif (is_string($_POST['search'])) {
            $search_value = sanitize_text_field($_POST['search']);
        }
    }
    error_log('=== DataTables Search Debug ===');
    error_log('Raw $_POST[search]: ' . json_encode($_POST['search'] ?? ''));
    error_log('Extracted search_value: ' . ($search_value ? 'Found: ' . $search_value : 'Empty'));
    error_log('Is Numeric: ' . (is_numeric($search_value) ? 'Yes' : 'No'));

    $order_column_index = 0;
    $order_direction = 'DESC';
    if (!empty($_POST['order']) && is_array($_POST['order'])) {
        $order_column_index = intval($_POST['order'][0]['column'] ?? 0);
        $order_direction = sanitize_text_field($_POST['order'][0]['dir'] ?? 'DESC');
    }

    $limit = min($length, 500);
    $offset = $start;

    // Determine sort column (only ID is sortable)
    $args = ['limit' => $limit, 'offset' => $offset, 'status' => 'any'];

    if ($order_column_index === 0) {
        // Sorting by ID
        $args['orderby'] = 'ID';
        $args['order'] = strtoupper($order_direction);
    } else {
        // Default: sort by date descending
        $args['orderby'] = 'date';
        $args['order'] = 'DESC';
    }

    $meta_query = ['relation' => 'AND'];

    if (($type = sanitize_text_field($_POST['type'] ?? '')) !== 'all') {
        if ($type === 'individual') {
            // "الأفراد" هم من ليس لديهم أي وسم "شركة" في أي من الحقول الممكنة
            $meta_query[] = [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => '_crm_order_type', 'value' => 'company', 'compare' => 'NOT EXISTS'],
                    ['key' => '_crm_order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'NOT IN']
                ],
                [
                    'relation' => 'OR',
                    ['key' => 'order_type', 'value' => 'company', 'compare' => 'NOT EXISTS'],
                    ['key' => 'order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'NOT IN']
                ]
            ];
        } else {
            // "الشركات" هم من لديهم وسم شركة في أي حقل
            $meta_query[] = [
                'relation' => 'OR',
                ['key' => '_crm_order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'IN'],
                ['key' => 'order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'IN']
            ];
        }
    }

    if (($status = sanitize_text_field($_POST['status'] ?? '')) !== 'all') {
        // البحث عن الحالة في المسميات الجديدة والقديمة
        $status_mq = [
            'relation' => 'OR',
            ['key' => '_crm_custom_status', 'value' => $status],
            ['key' => 'crm_status', 'value' => $status],
        ];
        // إذا كان الفلتر "جديد"، نضم الطلبات التي لا تملك أي حالة مسجلة
        if ($status === 'new') {
            $status_mq[] = ['key' => '_crm_custom_status', 'compare' => 'NOT EXISTS'];
        }
        $meta_query[] = $status_mq;
    }

    // Extract search parameter from DataTables
    $search_value = sanitize_text_field($_POST['search']['value'] ?? '');

    $user_id = get_current_user_id();
    $salesman_id = 0;

    // Determine scope based on context and user role
    if ($context === 'my') {
        // Essential: On 'My Orders' page, strictly scope to CURRENT user, regardless of role
        $salesman_id = $user_id;
    } elseif (isset($_POST['salesman_id']) && !empty($_POST['salesman_id'])) {
        // Manager can filter by specific salesman only on 'All' page or via specific filter
        $salesman_id = intval($_POST['salesman_id']);
    }

    if ($salesman_id > 0) {
        $meta_query[] = ['key' => '_crm_salesman_id', 'value' => (string)$salesman_id];
    }

    if (count($meta_query) > 1) {
        $args['meta_query'] = $meta_query;
        error_log('Meta Query Applied: ' . json_encode($meta_query));
    }

    // Get total records (without offset/limit but with base filters like context and salesman)
    $total_args = $args;
    unset($total_args['limit'], $total_args['offset']);

    // Build query for total count without search
    $total_base_args = ['status' => 'any'];
    $total_base_meta = ['relation' => 'AND'];

    // Apply type filter for total
    if (($type = sanitize_text_field($_POST['type'] ?? '')) !== 'all') {
        if ($type === 'individual') {
            $total_base_meta[] = [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => '_crm_order_type', 'value' => 'company', 'compare' => 'NOT EXISTS'],
                    ['key' => '_crm_order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'NOT IN']
                ],
                [
                    'relation' => 'OR',
                    ['key' => 'order_type', 'value' => 'company', 'compare' => 'NOT EXISTS'],
                    ['key' => 'order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'NOT IN']
                ]
            ];
        } else {
            $total_base_meta[] = [
                'relation' => 'OR',
                ['key' => '_crm_order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'IN'],
                ['key' => 'order_type', 'value' => ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'], 'compare' => 'IN']
            ];
        }
    }

    // Apply status filter for total
    if (($status = sanitize_text_field($_POST['status'] ?? '')) !== 'all') {
        $status_mq = [
            'relation' => 'OR',
            ['key' => '_crm_custom_status', 'value' => $status],
            ['key' => 'crm_status', 'value' => $status],
        ];
        if ($status === 'new') {
            $status_mq[] = ['key' => '_crm_custom_status', 'compare' => 'NOT EXISTS'];
        }
        $total_base_meta[] = $status_mq;
    }

    // Apply salesman filter for total
    if ($salesman_id > 0) {
        $total_base_meta[] = ['key' => '_crm_salesman_id', 'value' => (string)$salesman_id];
    }

    if (count($total_base_meta) > 1) {
        $total_base_args['meta_query'] = $total_base_meta;
    }

    // ====== ULTRA-FAST SQL QUERY (100x faster than wc_get_orders) ======
    $using_hpos = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
        $using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    $order_tbl = $wpdb->prefix . ($using_hpos ? 'wc_orders' : 'posts');
    $meta_tbl = $wpdb->prefix . ($using_hpos ? 'wc_orders_meta' : 'postmeta');
    $id_col_name = $using_hpos ? 'id' : 'ID';
    $type_col_name = $using_hpos ? 'type' : 'post_type';
    $status_col_name = $using_hpos ? 'status' : 'post_status';
    $meta_id_col_name = $using_hpos ? 'order_id' : 'post_id';

    // Base SQL (no LIMIT for counting total)
    $sql_base = "SELECT DISTINCT o.$id_col_name FROM $order_tbl o WHERE o.$type_col_name = 'shop_order' AND o.$status_col_name NOT IN ('trash', 'auto-draft')";

    // Type filter
    if ($type === 'individual') {
        $sql_base .= " AND NOT EXISTS (SELECT 1 FROM $meta_tbl m WHERE m.$meta_id_col_name = o.$id_col_name AND m.meta_key = '_crm_order_type' AND m.meta_value IN ('company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'))";
    } elseif ($type === 'company') {
        $sql_base .= " AND EXISTS (SELECT 1 FROM $meta_tbl m WHERE m.$meta_id_col_name = o.$id_col_name AND m.meta_key = '_crm_order_type' AND m.meta_value IN ('company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'))";
    }

    // Status filter
    if ($status !== 'all' && !empty($status)) {
        if ($status === 'new') {
            $sql_base .= " AND NOT EXISTS (SELECT 1 FROM $meta_tbl m WHERE m.$meta_id_col_name = o.$id_col_name AND m.meta_key = '_crm_custom_status')";
        } else {
            $sql_base .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $meta_tbl m WHERE m.$meta_id_col_name = o.$id_col_name AND m.meta_key = '_crm_custom_status' AND m.meta_value = %s)", $status);
        }
    }

    // Salesman filter
    if ($salesman_id > 0) {
        $sql_base .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM $meta_tbl m WHERE m.$meta_id_col_name = o.$id_col_name AND m.meta_key = '_crm_salesman_id' AND m.meta_value = %s)", (string)$salesman_id);
    }

    // Count TOTAL records
    $records_total = intval($wpdb->get_var("SELECT COUNT(*) FROM ($sql_base) as cnt"));

    // Build search filter SQL if search term provided
    // NOTE: We'll do client-side filtering since searching in concatenated names is complex in SQL
    $sql_with_search = $sql_base;
    $records_filtered = $records_total; // Initially set to total, we'll filter later

    // We'll filter the results after fetching them from the database
    // This is more reliable than trying to search concatenated first+last names in SQL

    // Determine sort column and direction (only ID is sortable)
    $sort_direction = (strtoupper($order_direction) === 'ASC') ? 'ASC' : 'DESC';
    $order_by_clause = " ORDER BY o.$id_col_name " . $sort_direction;
    if ($order_column_index !== 0) {
        // For non-ID columns, default to DESC by ID
        $order_by_clause = " ORDER BY o.$id_col_name DESC";
    }

    error_log('=== SQL Query Results ===');
    error_log('Total Records: ' . $records_total);
    error_log('Filtered Records: ' . $records_filtered);
    error_log('Search Applied: ' . (!empty($search_value) ? 'Yes (Server-side) - "' . $search_value . '"' : 'No'));
    error_log('Using HPOS: ' . ($using_hpos ? 'Yes' : 'No'));
    error_log('Order Table: ' . $order_tbl);
    error_log('Meta Table: ' . $meta_tbl);
    if (!empty($search_value)) {
        error_log('Base SQL: ' . substr($sql_base, 0, 200) . '...');
        error_log('With Search SQL: ' . substr($sql_with_search, 0, 300) . '...');
    }
    error_log('Order By Clause: ' . $order_by_clause);
    error_log('Offset: ' . $offset . ', Limit: ' . $limit);

    // Get paginated order IDs (with search filtering applied)
    $order_ids = $wpdb->get_col($sql_with_search . $order_by_clause . " LIMIT " . intval($offset) . ", " . intval($limit));
    error_log('Found order IDs: ' . json_encode($order_ids));

    // Convert IDs to order objects
    $orders = [];
    foreach ($order_ids as $oid) {
        $o = wc_get_order($oid);
        if ($o) $orders[] = $o;
    }
    $data = [];
    $statuses = car_crm_get_statuses();

    foreach ($orders as $order) {
        $id = $order->get_id();
        
        // استخدام get_meta بدلاً من get_post_meta لضمان التوافق مع HPOS
        $salesman_id = $order->get_meta('_crm_salesman_id', true);
        $salesman_name = $salesman_id ? get_userdata($salesman_id)->display_name : '';
        $salesman_badge = $salesman_name ? '<span class="badge bg-success">' . esc_html($salesman_name) . '</span>' : '<span class="badge bg-warning text-dark">غير مسند</span>';

        // تحسين التعرف على النوع لدعم كافة المسميات
        $raw_type = $order->get_meta('_crm_order_type', true) ?: $order->get_meta('order_type', true) ?: 'individual';
        if (in_array(strtolower($raw_type), ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'])) {
            $type_badge = '<span class="badge bg-success">شركة</span>';
        } else {
            $type_badge = '<span class="badge bg-info">فرد</span>';
        }

        $status_key = $order->get_meta('_crm_custom_status', true) ?: 'new';

        // دالة مساعدة لجلب البيانات مع الأولويات المتطابقة مع الـ Form
        $bank = $order->get_meta('_crm_customer_bank', true) ?: $order->get_meta('billing_type', true) ?: 'غير محدد';
        $job = $order->get_meta('_crm_customer_job', true) ?: $order->get_meta('_job', true) ?: 'غير مسجل';
        $salary = $order->get_meta('_crm_customer_salary', true) ?: $order->get_meta('billing_', true) ?: 'غير محدد';
        $nationality = $order->get_meta('_crm_customer_nationality', true) ?: $order->get_meta('_nationailty', true) ?: 'غير محدد';
        $city = $order->get_meta('_crm_customer_city', true) ?: $order->get_meta('_city', true) ?: 'غير محدد';
        
        // التعامل مع الالتزامات (قد تكون Array أو String)
        $raw_commitments = $order->get_meta('_crm_customer_commitments', true) ?: $order->get_meta('comemtment_', true) ?: $order->get_meta('_commitments', true);
        $commitments = is_array($raw_commitments) ? implode(', ', $raw_commitments) : ($raw_commitments ?: 'لا يوجد');

        $staff_note = $order->get_meta('_crm_staff_note', true) ?: $order->get_meta('order_comments', true) ?: '---';
        $commission_val = $order->get_meta('crm_a', true) ?: $order->get_meta('_crm_commission', true) ?: 0;

        $phone_digits = preg_replace('/\D/', '', $order->get_billing_phone());
        // Convert Saudi format (05XX) to international format (+966XX)
        if (strpos($phone_digits, '0') === 0) {
            $phone_clean = '966' . substr($phone_digits, 1);
        } else {
            $phone_clean = $phone_digits;
        }

        // Detect if order is manual or from website
        // Manual orders created via car_crm_manual_sale will have the _crm_salesman_id set to creator
        // Check if order has items from WooCommerce products (website order) or manual entry
        $is_manual_order = $order->get_meta('_crm_is_manual_order', true) ? true : false;

        // Alternative detection: if order was created without going through website checkout
        if (!$is_manual_order && method_exists($order, 'get_items')) {
            $items = $order->get_items();
            // If it has no items or minimal product info, it might be manual
            $is_manual_order = empty($items) || count($items) === 0;
        }

        $order_source = $is_manual_order ? '<span class="badge bg-warning text-dark">📝 يدوي</span>' : '<span class="badge bg-primary">🌐 موقع</span>';

        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $phone = $order->get_billing_phone();

        $data[] = [
            'id' => $id,
            'customer' => $customer_name,
            'phone' => $phone,
            'phone_clean' => $phone_clean,
            'type_badge' => $type_badge,
            'salesman_badge' => $salesman_badge,
            'status' => $statuses[$status_key] ?? 'جديد',
            'status_key' => $status_key,
            'staff_note' => $staff_note,
            'bank' => $bank,
            'job' => $job,
            'salary' => $salary,
            'nationality' => $nationality,
            'city' => $city,
            'commitments' => $commitments,
            'commission' => wc_price($commission_val),
            'order_source' => $order_source,
            'is_manual' => $is_manual_order,
            // Store raw values for search filtering
            '_search_text' => strtolower($customer_name . ' ' . $phone)
        ];
    }

    // Apply search filtering on the client-fetched data
    if (!empty($search_value)) {
        $search_lower = strtolower($search_value);
        $data = array_filter($data, function($item) use ($search_lower) {
            // Search in customer name, phone, and order ID
            $searchable = strtolower($item['id'] . ' ' . $item['_search_text']);
            return strpos($searchable, $search_lower) !== false;
        });
        // Re-index array after filtering
        $data = array_values($data);
        $records_filtered = count($data);
    }

    // Clean up internal search field before sending to frontend
    foreach ($data as &$item) {
        unset($item['_search_text']);
    }
    unset($item);

    // Return DataTables server-side format
    error_log('Sending response - Draw: ' . $draw . ', Total: ' . $records_total . ', Filtered: ' . $records_filtered . ', Data rows: ' . count($data));

    // Ensure response is valid JSON
    header('Content-Type: application/json; charset=utf-8');
    wp_send_json([
        'draw' => intval($draw),
        'recordsTotal' => intval($records_total),
        'recordsFiltered' => intval($records_filtered),
        'data' => $data
    ]);

    // Make sure we exit after sending the response
    wp_die();
});

/* =====================================================
   9. المودالات الكاملة
===================================================== */
function car_crm_render_all_modals() {
    $statuses = car_crm_get_statuses();
    $is_manager_or_admin = current_user_can('manage_options') || current_user_can('assign_crm_salesman');
    ?>
    <!-- Modal: بيانات العميل -->
    <div class="modal fade" id="customerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="modal-title">👤 بيانات العميل</h5>
                    <div>
                        <button type="button" class="btn btn-sm btn-warning me-2" id="toggleEditCustomerBtn" title="تعديل البيانات">
                            <i class="dashicons dashicons-edit"></i> <span id="editBtnText">تعديل</span>
                        </button>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body" id="customerBody">جاري التحميل...</div>
                <div class="modal-footer" id="customerFooter" style="display: none;">
                    <button type="button" class="btn btn-secondary" id="cancelEditCustomerBtn">إلغاء</button>
                    <button type="button" class="btn btn-primary" id="saveCustomerBtn">حفظ التغييرات</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: عناصر الطلب (السيارة) -->
    <div class="modal fade" id="itemsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">🚗 تفاصيل السيارة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="itemsBody">جاري التحميل...</div>
            </div>
        </div>
    </div>

    <!-- Modal: المستندات -->
    <div class="modal fade" id="docsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">📂 المستندات</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="docs_order_id">
                    <button id="uploadDocsBtn" class="btn btn-primary w-100 mb-3"><i class="dashicons dashicons-upload"></i> رفع مستندات</button>
                    <div id="docsList">جاري التحميل...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: تحديث الحالة -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-secondary text-white">
                    <h5 class="modal-title">⚙️ تحديث الحالة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="statusForm">
                    <div class="modal-body">
                        <input type="hidden" name="order_id" id="status_order_id">
                        <div class="mb-3">
                            <label class="form-label">الحالة</label>
                            <select name="status" class="form-select" id="statusSelect">
                                <?php foreach ($statuses as $k => $v): ?>
                                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: الملاحظات -->
    <div class="modal fade" id="notesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">📝 الملاحظات</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="notes_order_id">

                    <!-- Add New Note Section -->
                    <div class="mb-4 p-3 bg-light rounded">
                        <h6 class="fw-bold mb-2">✍️ إضافة ملاحظة جديدة</h6>
                        <textarea id="newNoteText" class="form-control mb-2" placeholder="اكتب الملاحظة هنا..." rows="3"></textarea>
                        <button class="btn btn-sm btn-success" onclick="window.carCrmAddNote()">
                            <i class="dashicons dashicons-yes"></i> إضافة الملاحظة
                        </button>
                    </div>

                    <!-- Notes History -->
                    <h6 class="fw-bold mb-3">📋 السجل</h6>
                    <div id="notesList" style="max-height: 400px; overflow-y: auto;">
                        <div class="text-center p-3 text-muted">جاري التحميل...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($is_manager_or_admin): ?>
    <!-- Modal: العمولة -->
    <div class="modal fade" id="commissionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">💰 العمولة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="commissionForm">
                    <div class="modal-body">
                        <input type="hidden" name="order_id" id="commission_order_id">
                        <div class="mb-3">
                            <label class="form-label">قيمة ثابتة (ر.س)</label>
                            <input type="number" step="0.01" name="fixed" class="form-control" id="commissionFixed">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">نسبة (%)</label>
                            <input type="number" step="0.1" name="percentage" class="form-control" id="commissionPercentage">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-success">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_manager_or_admin): ?>
    <!-- Modal: إسناد مندوب -->
    <div class="modal fade" id="assignSalesmanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-white">
                    <h5 class="modal-title">👤 إسناد إلى مندوب</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="assign_order_id">
                    <select id="salesman_select" class="form-select"></select>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-warning" onclick="carCrmSaveAssignment()">حفظ</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal: بيع يدوي -->
    <div class="modal fade" id="manualSaleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">✨ طلب يدوي جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="manualSaleForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">اسم العميل</label>
                                <input type="text" name="customer_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الجوال</label>
                                <input type="text" name="customer_phone" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">البنك</label>
                                <select name="customer_bank" class="form-select">
                                    <option value="">اختر البنك</option>
                                    <option value="البنك الأهلي السعودي">البنك الأهلي السعودي</option>
                                    <option value="بنك الرياض">بنك الرياض</option>
                                    <option value="البنك السعودي للاستثمار">البنك السعودي للاستثمار</option>
                                    <option value="بنك البلاد">بنك البلاد</option>
                                    <option value="بنك الجزيرة">بنك الجزيرة</option>
                                    <option value="بنك الراجحي">بنك الراجحي</option>
                                    <option value="بنك الإمارات دبي الوطني">بنك الإمارات دبي الوطني</option>
                                    <option value="بنك الاستثمار العربي السعودي">بنك الاستثمار العربي السعودي</option>
                                    <option value="بنك سامبا">بنك سامبا</option>
                                    <option value="بنك الخليج الدولي">بنك الخليج الدولي</option>
                                    <option value="بنك البيئة">بنك البيئة</option>
                                    <option value="بنك الإنماء">بنك الإنماء</option>
                                    <option value="بنك الأمل">بنك الأمل</option>
                                    <option value="الجزيرة تكافل">الجزيرة تكافل</option>
                                    <option value="بيت التمويل الكويتي">بيت التمويل الكويتي</option>
                                    <option value="بنك موضة">بنك موضة</option>
                                    <option value="البنك الأهلي الموحد">البنك الأهلي الموحد</option>
                                    <option value="بنك أبا كابيتال">بنك أبا كابيتال</option>
                                    <option value="أخرى">أخرى</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المهنة</label>
                                <input type="text" name="customer_job" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الراتب</label>
                                <input type="text" name="customer_salary" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الجنسية</label>
                                <select name="customer_nationality" class="form-select">
                                    <option value="">اختر الجنسية</option>
                                    <option value="سعودي">سعودي</option>
                                    <option value="غير سعودي (مقيم)">غير سعودي (مقيم)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المدينة</label>
                                <input type="text" name="customer_city" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الالتزامات</label>
                                <input type="text" name="customer_commitments" class="form-control">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label d-block fw-bold mb-2">نوع الطلب</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="order_type" id="manual_type_individual" value="individual" checked>
                                    <label class="form-check-label" for="manual_type_individual">افراد</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="order_type" id="manual_type_company" value="company">
                                    <label class="form-check-label" for="manual_type_company">شركات</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">السيارة</label>
                                <input type="text" id="manualProductSearch" class="form-control" placeholder="ابحث عن السيارة..." autocomplete="off" required>
                                <input type="hidden" name="product_id" id="manualProductId">
                                <div id="manualProductSuggestions" class="list-group mt-2" style="display: none; position: absolute; z-index: 1000; width: 100%; max-width: 400px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary w-100">إنشاء الطلب</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // --- Utils ---
    window.loadDocsList = function(orderId) {
        if (typeof jQuery === 'undefined') return;
        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_get_docs',
            order_id: orderId,
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                jQuery('#docsList').html(res.data.html);
            }
        });
    }

    // --- Customer Info ---
    window.carCrmCurrentOrderId = null;
    window.carCrmIsEditMode = false;

    window.carCrmOpenCustomer = function(orderId) {
        if (typeof jQuery === 'undefined') return;
        window.carCrmCurrentOrderId = orderId;
        window.carCrmIsEditMode = false;
        jQuery('#customerBody').html('<div class="text-center p-5"><div class="spinner-border text-primary"></div></div>');
        jQuery('#customerModal').modal('show');
        jQuery('#customerFooter').hide();
        jQuery('#toggleEditCustomerBtn').removeClass('btn-success').addClass('btn-warning');
        jQuery('#editBtnText').text('تعديل');

        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_customer_info',
            order_id: orderId,
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                let d = res.data;
                let html = `
                    <div class="customer-info-grid" id="customerViewMode">
                        <div class="info-item shadow-sm border-start border-4 border-primary rounded p-3 mb-3 bg-light">
                            <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-admin-users"></i> الاسم الكامل</div>
                            <div class="info-value h5 mb-0">${d.name}</div>
                        </div>
                        <div class="info-item shadow-sm border-start border-4 border-success rounded p-3 mb-3 bg-light">
                            <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-phone"></i> رقم الجوال</div>
                            <div class="info-value h5 mb-0"><a href="tel:${d.phone}" class="text-decoration-none">${d.phone}</a></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="info-item border rounded p-3 bg-white h-100">
                                    <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-bank"></i> البنك</div>
                                    <div class="info-value fw-semibold">${d.bank}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item border rounded p-3 bg-white h-100">
                                    <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-businessman"></i> المهنة</div>
                                    <div class="info-value fw-semibold">${d.job}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item border rounded p-3 bg-white h-100">
                                    <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-money-alt"></i> الراتب</div>
                                    <div class="info-value fw-semibold text-success">${d.salary}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item border rounded p-3 bg-white h-100">
                                    <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-admin-site"></i> الجنسية</div>
                                    <div class="info-value fw-semibold">${d.nationality}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item border rounded p-3 bg-white h-100">
                                    <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-location"></i> المدينة</div>
                                    <div class="info-value fw-semibold">${d.city}</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-item border rounded p-3 bg-white h-100">
                                    <div class="info-label text-muted small fw-bold mb-1"><i class="dashicons dashicons-warning"></i> الالتزامات</div>
                                    <div class="info-value fw-semibold text-danger">${d.commitments}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;

                let editHtml = `
                    <form id="customerEditForm" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">الاسم الأول</label>
                                <input type="text" name="first_name" class="form-control" value="${d.first_name}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الاسم الأخير</label>
                                <input type="text" name="last_name" class="form-control" value="${d.last_name}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الهاتف</label>
                                <input type="text" name="phone" class="form-control" value="${d.phone}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">البنك</label>
                                <select name="bank" class="form-select">
                                    <option value="">اختر البنك</option>
                                    <option value="البنك الأهلي السعودي">البنك الأهلي السعودي</option>
                                    <option value="بنك الرياض">بنك الرياض</option>
                                    <option value="البنك السعودي للاستثمار">البنك السعودي للاستثمار</option>
                                    <option value="بنك البلاد">بنك البلاد</option>
                                    <option value="بنك الجزيرة">بنك الجزيرة</option>
                                    <option value="بنك الراجحي">بنك الراجحي</option>
                                    <option value="بنك الإمارات دبي الوطني">بنك الإمارات دبي الوطني</option>
                                    <option value="بنك الاستثمار العربي السعودي">بنك الاستثمار العربي السعودي</option>
                                    <option value="بنك سامبا">بنك سامبا</option>
                                    <option value="بنك الخليج الدولي">بنك الخليج الدولي</option>
                                    <option value="بنك البيئة">بنك البيئة</option>
                                    <option value="بنك الإنماء">بنك الإنماء</option>
                                    <option value="بنك الأمل">بنك الأمل</option>
                                    <option value="الجزيرة تكافل">الجزيرة تكافل</option>
                                    <option value="بيت التمويل الكويتي">بيت التمويل الكويتي</option>
                                    <option value="بنك موضة">بنك موضة</option>
                                    <option value="البنك الأهلي الموحد">البنك الأهلي الموحد</option>
                                    <option value="بنك أبا كابيتال">بنك أبا كابيتال</option>
                                    <option value="أخرى">أخرى</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المهنة</label>
                                <input type="text" name="job" class="form-control" value="${d.job}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الراتب</label>
                                <input type="text" name="salary" class="form-control" value="${d.salary}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">الجنسية</label>
                                <select name="nationality" class="form-select">
                                    <option value="">اختر الجنسية</option>
                                    <option value="سعودي">سعودي</option>
                                    <option value="غير سعودي (مقيم)">غير سعودي (مقيم)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">المدينة</label>
                                <input type="text" name="city" class="form-control" value="${d.city}">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label">الالتزامات</label>
                                <textarea name="commitments" class="form-control" rows="2">${d.commitments}</textarea>
                            </div>
                        </div>
                    </form>
                `;

                jQuery('#customerBody').html(html + editHtml);
                window.carCrmCustomerData = d;

                // Set select values after form is inserted
                if (d.bank) {
                    jQuery('#customerEditForm select[name="bank"]').val(d.bank);
                }
                if (d.nationality) {
                    jQuery('#customerEditForm select[name="nationality"]').val(d.nationality);
                }
            }
        });
    };

    // Toggle edit mode
    jQuery(document).on('click', '#toggleEditCustomerBtn', function() {
        window.carCrmIsEditMode = !window.carCrmIsEditMode;

        if (window.carCrmIsEditMode) {
            jQuery('#customerViewMode').hide();
            jQuery('#customerEditForm').show();
            jQuery('#customerFooter').show();
            jQuery('#toggleEditCustomerBtn').removeClass('btn-warning').addClass('btn-success');
            jQuery('#editBtnText').text('إلغاء التعديل');
        } else {
            jQuery('#customerViewMode').show();
            jQuery('#customerEditForm').hide();
            jQuery('#customerFooter').hide();
            jQuery('#toggleEditCustomerBtn').removeClass('btn-success').addClass('btn-warning');
            jQuery('#editBtnText').text('تعديل');
        }
    });

    // Cancel edit
    jQuery(document).on('click', '#cancelEditCustomerBtn', function() {
        window.carCrmIsEditMode = false;
        jQuery('#customerViewMode').show();
        jQuery('#customerEditForm').hide();
        jQuery('#customerFooter').hide();
        jQuery('#toggleEditCustomerBtn').removeClass('btn-success').addClass('btn-warning');
        jQuery('#editBtnText').text('تعديل');
    });

    // Save customer changes
    jQuery(document).on('click', '#saveCustomerBtn', function() {
        const formData = jQuery('#customerEditForm').serialize();
        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_update_customer_info',
            order_id: window.carCrmCurrentOrderId,
            ...Object.fromEntries(new URLSearchParams(formData)),
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                alert('تم حفظ البيانات بنجاح!');
                // إعادة تحميل الجدول
                if (window.ordersTable && window.ordersTable.ajax) {
                    window.ordersTable.ajax.reload();
                }
                window.carCrmOpenCustomer(window.carCrmCurrentOrderId);
            } else {
                alert('حدث خطأ: ' + (res.data || 'حاول مجدداً'));
            }
        });
    });

    window.carCrmOpenManualOrder = function() {
        jQuery('#manualSaleModal').modal('show');
    };

    // --- Order Items ---
    window.carCrmOpenItems = function(orderId) {
        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_order_items',
            order_id: orderId,
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                jQuery('#itemsBody').html(res.data.html);
                jQuery('#itemsModal').modal('show');
            }
        });
    };

    // --- Documents ---
    window.carCrmOpenDocs = function(orderId) {
        jQuery('#docs_order_id').val(orderId);
        jQuery('#docsModal').modal('show');
        window.loadDocsList(orderId);
    };
    jQuery('#uploadDocsBtn').on('click', function() {
        const orderId = jQuery('#docs_order_id').val();
        const frame = wp.media({
            title: 'اختر المستندات',
            multiple: true,
            library: { type: ['image', 'application/pdf', 'text/plain'] }
        });
        frame.on('select', function() {
            const ids = frame.state().get('selection').toJSON().map(item => item.id);
            jQuery.post(CarCRM.ajax_url, {
                action: 'car_crm_add_docs',
                order_id: orderId,
                doc_ids: ids,
                nonce: CarCRM.nonce
            }, function() {
                window.loadDocsList(orderId);
            });
        });
        frame.open();
    });
    window.carCrmRemoveDoc = function(orderId, docId) {
        if (!confirm('هل تريد حذف هذا المستند؟')) return;
        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_remove_doc',
            order_id: orderId,
            doc_id: docId,
            nonce: CarCRM.nonce
        }, function() {
            window.loadDocsList(orderId);
        });
    };

    // --- Status Update ---
    window.carCrmOpenStatus = function(orderId, status) {
        jQuery('#status_order_id').val(orderId);
        jQuery('#statusSelect').val(status);
        jQuery('#statusModal').modal('show');
    };
    jQuery('#statusForm').on('submit', function(e) {
        e.preventDefault();
        jQuery.post(CarCRM.ajax_url, jQuery(this).serialize() + '&action=car_crm_update_status&nonce=' + CarCRM.nonce, function(res) {
            if (res.success) {
                jQuery('#statusModal').modal('hide');
                if (window.ordersTable && window.ordersTable.ajax) {
                    window.ordersTable.ajax.reload();
                }
            }
        });
    });

    // --- Notes ---
    window.carCrmOpenNotes = function(orderId) {
        jQuery('#notes_order_id').val(orderId);
        jQuery('#notesList').html('<div class="text-center p-3"><div class="spinner-border text-primary spinner-border-sm"></div></div>');
        jQuery('#notesModal').modal('show');

        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_get_notes',
            order_id: orderId,
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success && res.data.notes) {
                let html = '';
                if (res.data.notes.length === 0) {
                    html = '<div class="alert alert-info">لا توجد ملاحظات بعد</div>';
                } else {
                    res.data.notes.forEach(note => {
                        html += '<div class="card mb-2 border-start border-4 border-success">';
                        html += '<div class="card-body p-2">';
                        html += '<div class="d-flex justify-content-between mb-1">';
                        html += '<strong>' + note.author + '</strong>';
                        html += '<small class="text-muted">' + note.date + '</small>';
                        html += '</div>';
                        html += '<p class="mb-0">' + note.text + '</p>';
                        html += '</div>';
                        html += '</div>';
                    });
                }
                jQuery('#notesList').html(html);
            }
        });
    };

    window.carCrmAddNote = function() {
        const orderId = jQuery('#notes_order_id').val();
        const noteText = jQuery('#newNoteText').val();

        if (!noteText.trim()) {
            alert('يرجى كتابة ملاحظة');
            return;
        }

        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_add_note',
            order_id: orderId,
            note_text: noteText,
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                jQuery('#newNoteText').val('');
                window.carCrmOpenNotes(orderId);
            }
        });
    };

    // --- Commission ---
    window.carCrmOpenCommission = function(orderId) {
        jQuery('#commission_order_id').val(orderId);
        jQuery('#commissionModal').modal('show');
    };
    jQuery('#commissionForm').on('submit', function(e) {
        e.preventDefault();
        jQuery.post(CarCRM.ajax_url, jQuery(this).serialize() + '&action=car_crm_update_commission&nonce=' + CarCRM.nonce, function() {
            jQuery('#commissionModal').modal('hide');
            if (window.loadOrders) window.loadOrders();
        });
    });

    // --- Assign Salesman ---
    window.carCrmAssignSalesman = function(orderId) {
        jQuery('#assign_order_id').val(orderId);
        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_get_salesmen',
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success) {
                let html = '<option value="">إزالة الإسناد</option>';
                res.data.forEach(s => {
                    html += `<option value="${s.id}">${s.name}</option>`;
                });
                jQuery('#salesman_select').html(html);
                jQuery('#assignSalesmanModal').modal('show');
            }
        });
    };
    window.carCrmSaveAssignment = function() {
        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_assign_salesman',
            order_id: jQuery('#assign_order_id').val(),
            salesman_id: jQuery('#salesman_select').val(),
            nonce: CarCRM.nonce
        }, function() {
            jQuery('#assignSalesmanModal').modal('hide');
            if (window.loadOrders) window.loadOrders();
        });
    };

    // --- Product Search for Manual Sale ---
    let productSearchTimeout;
    jQuery('#manualProductSearch').on('keyup', function() {
        const query = jQuery(this).val();
        clearTimeout(productSearchTimeout);

        if (query.length < 2) {
            jQuery('#manualProductSuggestions').hide();
            return;
        }

        productSearchTimeout = setTimeout(function() {
            jQuery.post(CarCRM.ajax_url, {
                action: 'car_crm_search_products',
                search: query,
                nonce: CarCRM.nonce
            }, function(res) {
                if (res.success && res.data.length > 0) {
                    let html = '';
                    res.data.forEach(product => {
                        html += '<button type="button" class="list-group-item list-group-item-action" ' +
                                'onclick="jQuery(\'#manualProductSearch\').val(\'' + product.name.replace(/'/g, "\\'") + '\'); ' +
                                'jQuery(\'#manualProductId\').val(' + product.id + '); ' +
                                'jQuery(\'#manualProductSuggestions\').hide();">' +
                                '<strong>' + product.name + '</strong><br><small class="text-muted">ID: ' + product.id + '</small>' +
                                '</button>';
                    });
                    jQuery('#manualProductSuggestions').html(html).show();
                } else {
                    jQuery('#manualProductSuggestions').hide();
                }
            });
        }, 300);
    });

    // Hide suggestions when clicking outside
    jQuery(document).on('click', function(e) {
        if (!jQuery(e.target).closest('#manualProductSearch, #manualProductSuggestions').length) {
            jQuery('#manualProductSuggestions').hide();
        }
    });

    // --- Manual Sale ---
    jQuery('#manualSaleForm').on('submit', function(e) {
        e.preventDefault();

        // Validate that a product was selected
        if (!jQuery('#manualProductId').val()) {
            alert('يرجى اختيار سيارة من القائمة');
            return;
        }

        jQuery.post(CarCRM.ajax_url, jQuery(this).serialize() + '&action=car_crm_manual_sale&nonce=' + CarCRM.nonce, function(res) {
            if (res.success) {
                alert('تم إنشاء الطلب بنجاح!');
                jQuery('#manualSaleModal').modal('hide');
                jQuery('#manualSaleForm')[0].reset();
                jQuery('#manualProductId').val('');
                // إعادة تحميل جدول الطلبات
                if (window.ordersTable && window.ordersTable.ajax) {
                    window.ordersTable.ajax.reload();
                }
            } else {
                alert('خطأ: ' + (res.data || 'فشل إنشاء الطلب'));
            }
        });
    });

    // --- Delete Order (Admin Only) ---
    window.carCrmDeleteOrder = function(orderId) {
        if (!confirm('هل أنت متأكد من رغبتك في حذف الطلب #' + orderId + '؟\n\nهذا الإجراء لا يمكن التراجع عنه.')) {
            return;
        }

        jQuery.post(CarCRM.ajax_url, {
            action: 'car_crm_delete_order',
            order_id: orderId,
            nonce: CarCRM.nonce
        }, function(res) {
            console.log('Delete response:', res);
            if (res.success) {
                alert('تم حذف الطلب بنجاح!');
                // إعادة تحميل جدول الطلبات
                if (window.ordersTable && window.ordersTable.ajax) {
                    window.ordersTable.ajax.reload();
                }
            } else {
                var errorMsg = res.data && res.data.message ? res.data.message : 'فشل حذف الطلب';
                alert('خطأ: ' + errorMsg);
            }
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.log('Delete AJAX Error:', {status: jqXHR.status, statusText: jqXHR.statusText, error: errorThrown});
            alert('خطأ في الاتصال: ' + errorThrown);
        });
    };
    </script>
    <?php
}

/* =====================================================
   10. AJAX Handlers
===================================================== */

// بيانات العميل
add_action('wp_ajax_car_crm_customer_info', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $order = wc_get_order($id);
    if (!$order) wp_send_json_error();

    wp_send_json_success([
        'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        'first_name' => $order->get_billing_first_name(),
        'last_name' => $order->get_billing_last_name(),
        'phone' => $order->get_billing_phone(),
        'bank' => $order->get_meta('_crm_customer_bank', true) ?: $order->get_meta('billing_type', true) ?: 'غير محدد',
        'job' => $order->get_meta('_crm_customer_job', true) ?: $order->get_meta('_job', true) ?: 'غير مسجل',
        'salary' => $order->get_meta('_crm_customer_salary', true) ?: $order->get_meta('billing_', true) ?: 'غير محدد',
        'nationality' => $order->get_meta('_crm_customer_nationality', true) ?: $order->get_meta('_nationailty', true) ?: 'غير محدد',
        'city' => $order->get_meta('_crm_customer_city', true) ?: $order->get_meta('_city', true) ?: 'غير محدد',
        'commitments' => $order->get_meta('_crm_customer_commitments', true) ?: $order->get_meta('comemtment_', true) ?: 'لا يوجد'
    ]);
});

// تحديث بيانات العميل
add_action('wp_ajax_car_crm_update_customer_info', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $order = wc_get_order($id);
    if (!$order) {
        wp_send_json_error('الطلب غير موجود');
        return;
    }

    // التحقق من الصلاحيات
    if (!current_user_can('manage_options') && !current_user_can('edit_all_crm_leads')) {
        wp_send_json_error('ليس لديك صلاحية للتعديل');
        return;
    }

    // تحديث بيانات العميل
    if (!empty($_POST['first_name'])) {
        $order->set_billing_first_name(sanitize_text_field($_POST['first_name']));
    }
    if (!empty($_POST['last_name'])) {
        $order->set_billing_last_name(sanitize_text_field($_POST['last_name']));
    }
    if (!empty($_POST['phone'])) {
        $order->set_billing_phone(sanitize_text_field($_POST['phone']));
    }

    // تحديث meta data
    $order->update_meta_data('_crm_customer_bank', sanitize_text_field($_POST['bank'] ?? ''));
    $order->update_meta_data('_crm_customer_job', sanitize_text_field($_POST['job'] ?? ''));
    $order->update_meta_data('_crm_customer_salary', sanitize_text_field($_POST['salary'] ?? ''));
    $order->update_meta_data('_crm_customer_nationality', sanitize_text_field($_POST['nationality'] ?? ''));
    $order->update_meta_data('_crm_customer_city', sanitize_text_field($_POST['city'] ?? ''));
    $order->update_meta_data('_crm_customer_commitments', sanitize_text_field($_POST['commitments'] ?? ''));

    $order->save();

    wp_send_json_success(['message' => 'تم تحديث البيانات بنجاح']);
});

// عناصر الطلب
add_action('wp_ajax_car_crm_order_items', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $order = wc_get_order($id);
    if (!$order) wp_send_json_error();

    $html = '<table class="table table-sm"><thead><tr><th>المنتج</th><th>السعر</th><th>الكمية</th><th>الإجمالي</th></tr></thead><tbody>';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $html .= '<tr>';
        $html .= '<td>' . ($product ? $product->get_name() : 'منتج محذوف') . '</td>';
        $html .= '<td>' . wc_price($item->get_total()) . '</td>';
        $html .= '<td>' . $item->get_quantity() . '</td>';
        $html .= '<td>' . wc_price($item->get_total()) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    wp_send_json_success(['html' => $html]);
});

// المستندات
add_action('wp_ajax_car_crm_get_docs', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $doc_ids = get_post_meta($id, '_crm_docs', true) ?: [];
    $html = '';
    if (empty($doc_ids)) {
        $html = '<p class="text-muted text-center">لا توجد مستندات.</p>';
    } else {
        foreach ($doc_ids as $doc_id) {
            $url = wp_get_attachment_url($doc_id);
            $title = get_the_title($doc_id);
            $html .= '<div class="d-flex justify-content-between align-items-center p-2 border rounded mb-2">';
            $html .= '<a href="' . esc_url($url) . '" target="_blank">' . esc_html($title) . '</a>';
            $html .= '<button class="btn btn-sm btn-danger" onclick="carCrmRemoveDoc(' . $id . ', ' . $doc_id . ')">حذف</button>';
            $html .= '</div>';
        }
    }
    wp_send_json_success(['html' => $html]);
});

add_action('wp_ajax_car_crm_add_docs', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $new_ids = array_map('intval', $_POST['doc_ids']);
    $existing = get_post_meta($id, '_crm_docs', true) ?: [];
    $updated = array_unique(array_merge($existing, $new_ids));
    update_post_meta($id, '_crm_docs', $updated);
    wp_send_json_success();
});

add_action('wp_ajax_car_crm_remove_doc', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $doc_id = intval($_POST['doc_id']);
    $docs = get_post_meta($id, '_crm_docs', true) ?: [];
    $docs = array_values(array_diff($docs, [$doc_id]));
    update_post_meta($id, '_crm_docs', $docs);
    wp_send_json_success();
});

// تحديث الحالة
add_action('wp_ajax_car_crm_update_status', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $order = wc_get_order($id);
    if ($order) {
        $order->update_meta_data('_crm_custom_status', sanitize_text_field($_POST['status']));
        $order->save();
        wp_send_json_success(['message' => 'تم تحديث الحالة بنجاح']);
    } else {
        wp_send_json_error('Order not found');
    }
});

// جلب الملاحظات
add_action('wp_ajax_car_crm_get_notes', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $order_id = intval($_POST['order_id']);

    $notes_raw = get_post_meta($order_id, '_crm_notes', true) ?: [];
    $notes = [];

    if (is_array($notes_raw)) {
        foreach ($notes_raw as $note) {
            $user = get_userdata($note['user_id']);
            $notes[] = [
                'text' => $note['text'],
                'author' => $user ? $user->display_name : 'مستخدم غير معروف',
                'date' => date('d/m/Y H:i', strtotime($note['date']))
            ];
        }
    }

    // ترتيب الملاحظات الأحدث أولاً
    $notes = array_reverse($notes);

    wp_send_json_success(['notes' => $notes]);
});

// إضافة ملاحظة جديدة
add_action('wp_ajax_car_crm_add_note', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $order_id = intval($_POST['order_id']);
    $note_text = sanitize_textarea_field($_POST['note_text']);

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error('Order not found');
        return;
    }

    // جلب الملاحظات الحالية
    $notes = get_post_meta($order_id, '_crm_notes', true) ?: [];

    // إضافة ملاحظة جديدة
    $new_note = [
        'text' => $note_text,
        'user_id' => get_current_user_id(),
        'date' => current_time('mysql')
    ];

    if (!is_array($notes)) {
        $notes = [];
    }

    $notes[] = $new_note;

    // حفظ الملاحظات المحدثة
    update_post_meta($order_id, '_crm_notes', $notes);

    wp_send_json_success(['message' => 'تمت إضافة الملاحظة بنجاح']);
});

// العمولة
add_action('wp_ajax_car_crm_update_commission', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $order = wc_get_order($id);
    if (!$order) {
        wp_send_json_error('Order not found');
        return;
    }
    
    $fixed = floatval($_POST['fixed'] ?? 0);
    $perc = floatval($_POST['percentage'] ?? 0);
    $value = $perc > 0 ? ($perc / 100) * $order->get_total() : $fixed;
    
    $order->update_meta_data('crm_a', $value);
    $order->save();
    car_crm_send_json_success();
});

// قائمة المناديب (توسيع لتشمل جميع المستخدمين)
add_action('wp_ajax_car_crm_get_salesmen', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    
    // جلب جميع المستخدمين لتمكين إسناد أي شخص
    $users = get_users([
        'fields' => ['ID', 'display_name'],
        'orderby' => 'display_name'
    ]);
    
    $data = [];
    foreach ($users as $user) {
        $u = get_userdata($user->ID);
        $role_label = '';
        if (!empty($u->roles)) {
            $role_name = $u->roles[0];
            $role_label = isset($wp_roles->role_names[$role_name]) ? ' (' . $wp_roles->role_names[$role_name] . ')' : '';
        }
        $data[] = ['id' => $user->ID, 'name' => $user->display_name . $role_label];
    }
    car_crm_send_json_success($data);
});

// إسناد مندوب
add_action('wp_ajax_car_crm_assign_salesman', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    $id = intval($_POST['order_id']);
    $sid = intval($_POST['salesman_id']);
    $order = wc_get_order($id);
    if ($order) {
        $order->update_meta_data('_crm_salesman_id', $sid ?: '');
        $order->save();
        car_crm_send_json_success();
    } else {
        wp_send_json_error('Order not found');
    }
});

// البحث عن المنتجات (السيارات)
add_action('wp_ajax_car_crm_search_products', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');

    $search_term = sanitize_text_field($_POST['search'] ?? '');
    if (empty($search_term)) {
        wp_send_json_success([]);
        return;
    }

    // البحث في WooCommerce عن المنتجات
    $args = [
        'limit' => 20,
        's' => $search_term,
        'status' => 'publish',
        'type' => 'simple'
    ];

    $products = wc_get_products($args);
    $results = [];

    foreach ($products as $product) {
        $results[] = [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price' => wc_price($product->get_price())
        ];
    }

    wp_send_json_success($results);
});

// بيع يدوي
add_action('wp_ajax_car_crm_manual_sale', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');

    // التحقق من الصلاحية
    if (!current_user_can('create_manual_sale')) {
        wp_send_json_error(['message' => 'ليس لديك صلاحية لإنشاء مبيعات يدوية']);
        return;
    }

    $order = wc_create_order();
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    if ($product) {
        $order->add_product($product, 1);
    }
    
    $order->set_billing_first_name(sanitize_text_field($_POST['customer_name']));
    $order->set_billing_phone(sanitize_text_field($_POST['customer_phone']));
    
    // حفظ الـ Meta Data باستخدام المسميات الموحدة والمدعومة في الفلاتر
    $order_type = sanitize_text_field($_POST['order_type']);
    $order->update_meta_data('_crm_order_type', $order_type);
    
    // حفظ البنك والمهنة والبيانات الأخرى
    $order->update_meta_data('_crm_customer_bank', sanitize_text_field($_POST['customer_bank']));
    $order->update_meta_data('_crm_customer_job', sanitize_text_field($_POST['customer_job']));
    $order->update_meta_data('_crm_customer_salary', sanitize_text_field($_POST['customer_salary']));
    $order->update_meta_data('_crm_customer_nationality', sanitize_text_field($_POST['customer_nationality']));
    $order->update_meta_data('_crm_customer_city', sanitize_text_field($_POST['customer_city']));
    $order->update_meta_data('_crm_customer_commitments', sanitize_text_field($_POST['customer_commitments']));
    
    $order->update_meta_data('_crm_custom_status', 'new');
    $order->update_meta_data('_crm_salesman_id', get_current_user_id());
    $order->update_meta_data('_crm_is_manual_order', '1'); // Mark as manual order

    $order->calculate_totals();
    $order->save();

    car_crm_send_json_success(['order_id' => $order->get_id()]);
});

// حذف الطلب (Admin Only)
add_action('wp_ajax_car_crm_delete_order', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');

    // Check admin capability only
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'ليس لديك صلاحية لحذف الطلبات']);
    }

    $order_id = intval($_POST['order_id']);
    $order = wc_get_order($order_id);

    if (!$order) {
        wp_send_json_error(['message' => 'الطلب غير موجود']);
    }

    // For WooCommerce orders, use the order's delete method
    // This works for both HPOS and legacy postmeta
    try {
        $order->delete(true); // true = force delete
        error_log('Order #' . $order_id . ' deleted successfully');
        wp_send_json_success(['message' => 'تم حذف الطلب بنجاح']);
    } catch (Exception $e) {
        error_log('Error deleting order #' . $order_id . ': ' . $e->getMessage());
        wp_send_json_error(['message' => 'خطأ في حذف الطلب']);
    }
});

// إحصائيات الداشبورد
add_action('wp_ajax_car_crm_get_dashboard_stats', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    global $wpdb;

    $statuses = car_crm_get_statuses();

    // التحقق من تفعيل الـ HPOS
    $using_hpos = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
        $using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    $order_table = $wpdb->prefix . ($using_hpos ? 'wc_orders' : 'posts');
    $meta_table  = $wpdb->prefix . ($using_hpos ? 'wc_orders_meta' : 'postmeta');
    $id_col      = $using_hpos ? 'id' : 'ID';
    $type_col    = $using_hpos ? 'type' : 'post_type';
    $status_col  = $using_hpos ? 'status' : 'post_status';
    $meta_id_col = $using_hpos ? 'order_id' : 'post_id';

    // 1. توزيع الحالات (للرسم البياني الدائري)
    $status_counts = [];
    foreach ($statuses as $slug => $label) {
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT o.$id_col)
            FROM $order_table o
            JOIN $meta_table om ON o.$id_col = om.$meta_id_col
            WHERE o.$type_col = 'shop_order'
            AND o.$status_col NOT IN ('trash', 'auto-draft')
            AND om.meta_key = '_crm_custom_status'
            AND om.meta_value = %s
        ", $slug));
        $status_counts[] = [
            'slug' => $slug,
            'label' => $label,
            'count' => (int)$count
        ];
    }

    // 2. توزيع الحالات حسب النوع (الأفراد والشركات)
    $status_type_counts = [];
    foreach ($statuses as $slug => $label) {
        // عد الأفراد لهذه الحالة
        $individual_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT o.$id_col)
            FROM $order_table o
            JOIN $meta_table om_status ON o.$id_col = om_status.$meta_id_col AND om_status.meta_key = '_crm_custom_status' AND om_status.meta_value = %s
            LEFT JOIN $meta_table om_type ON o.$id_col = om_type.$meta_id_col AND om_type.meta_key = '_crm_order_type'
            LEFT JOIN $meta_table om_type_old ON o.$id_col = om_type_old.$meta_id_col AND om_type_old.meta_key = 'order_type'
            WHERE o.$type_col = 'shop_order'
            AND o.$status_col NOT IN ('trash', 'auto-draft')
            AND (
                om_type.meta_value NOT IN ('company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة')
                OR om_type.meta_key IS NULL
            )
            AND (
                om_type_old.meta_value NOT IN ('company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة')
                OR om_type_old.meta_key IS NULL
            )
        ", $slug));

        // عد الشركات لهذه الحالة
        $company_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT o.$id_col)
            FROM $order_table o
            JOIN $meta_table om_status ON o.$id_col = om_status.$meta_id_col AND om_status.meta_key = '_crm_custom_status' AND om_status.meta_value = %s
            LEFT JOIN $meta_table om_type ON o.$id_col = om_type.$meta_id_col AND om_type.meta_key = '_crm_order_type'
            LEFT JOIN $meta_table om_type_old ON o.$id_col = om_type_old.$meta_id_col AND om_type_old.meta_key = 'order_type'
            WHERE o.$type_col = 'shop_order'
            AND o.$status_col NOT IN ('trash', 'auto-draft')
            AND (
                om_type.meta_value IN ('company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة')
                OR om_type_old.meta_value IN ('company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة')
            )
        ", $slug));

        $status_type_counts[] = [
            'status' => $slug,
            'label' => $label,
            'individual_count' => (int)$individual_count,
            'company_count' => (int)$company_count
        ];
    }

    // 3. أداء المناديب (للطلب المحول لبيع - status = 'sold' أو 'completed' أو 'approved')
    $salesman_stats = [];
    $salesmen = get_users(['role__in' => ['car_crm_salesman', 'administrator', 'editor']]);
    foreach ($salesmen as $sm) {
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT o.$id_col)
            FROM $order_table o
            JOIN $meta_table om1 ON o.$id_col = om1.$meta_id_col
            JOIN $meta_table om2 ON o.$id_col = om2.$meta_id_col
            WHERE o.$type_col = 'shop_order'
            AND o.$status_col NOT IN ('trash', 'auto-draft')
            AND om1.meta_key = '_crm_salesman_id' AND om1.meta_value = %d
            AND om2.meta_key = '_crm_custom_status' AND om2.meta_value IN ('sold', 'approved', 'completed')
        ", $sm->ID));
        if ($count > 0) {
            $salesman_stats[] = ['name' => $sm->display_name, 'count' => (int)$count];
        }
    }

    car_crm_send_json_success([
        'status_dist' => $status_counts,
        'status_type_counts' => $status_type_counts,
        'salesman_perf' => $salesman_stats
    ]);
});

/* =====================================================
   10.5 AJAX: احصائيات Personal Dashboard
===================================================== */
add_action('wp_ajax_car_crm_get_personal_dashboard_stats', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    global $wpdb;

    $user_id = get_current_user_id();
    $statuses = car_crm_get_statuses();

    // التحقق من تفعيل الـ HPOS
    $using_hpos = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
        $using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    $order_table = $wpdb->prefix . ($using_hpos ? 'wc_orders' : 'posts');
    $meta_table  = $wpdb->prefix . ($using_hpos ? 'wc_orders_meta' : 'postmeta');
    $id_col      = $using_hpos ? 'id' : 'ID';
    $type_col    = $using_hpos ? 'type' : 'post_type';
    $status_col  = $using_hpos ? 'status' : 'post_status';
    $meta_id_col = $using_hpos ? 'order_id' : 'post_id';
    $date_col    = $using_hpos ? 'date_created' : 'post_date';

    // عد الطلبات حسب الحالة
    $completed_count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT o.$id_col)
        FROM $order_table o
        JOIN $meta_table om ON o.$id_col = om.$meta_id_col
        WHERE o.$type_col = 'shop_order'
        AND o.$status_col NOT IN ('trash', 'auto-draft')
        AND om.meta_key = '_crm_custom_status' AND om.meta_value = 'completed'
        AND o.customer_id = %d
    ", $user_id));

    $working_count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT o.$id_col)
        FROM $order_table o
        JOIN $meta_table om ON o.$id_col = om.$meta_id_col
        WHERE o.$type_col = 'shop_order'
        AND o.$status_col NOT IN ('trash', 'auto-draft')
        AND om.meta_key = '_crm_custom_status' AND om.meta_value IN ('working', 'working_urgent')
        AND o.customer_id = %d
    ", $user_id));

    $pending_count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT o.$id_col)
        FROM $order_table o
        JOIN $meta_table om ON o.$id_col = om.$meta_id_col
        WHERE o.$type_col = 'shop_order'
        AND o.$status_col NOT IN ('trash', 'auto-draft')
        AND om.meta_key = '_crm_custom_status' AND om.meta_value IN ('new', 'inquiry', 'inquiry_new')
        AND o.customer_id = %d
    ", $user_id));

    $rejected_count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(DISTINCT o.$id_col)
        FROM $order_table o
        JOIN $meta_table om ON o.$id_col = om.$meta_id_col
        WHERE o.$type_col = 'shop_order'
        AND o.$status_col NOT IN ('trash', 'auto-draft')
        AND om.meta_key = '_crm_custom_status' AND om.meta_value IN ('rejected', 'unqualified')
        AND o.customer_id = %d
    ", $user_id));

    // توزيع الحالات
    $status_dist = [];
    foreach ($statuses as $slug => $label) {
        $count = (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT o.$id_col)
            FROM $order_table o
            JOIN $meta_table om ON o.$id_col = om.$meta_id_col
            WHERE o.$type_col = 'shop_order'
            AND o.$status_col NOT IN ('trash', 'auto-draft')
            AND om.meta_key = '_crm_custom_status' AND om.meta_value = %s
            AND o.customer_id = %d
        ", $slug, $user_id));

        if ($count > 0) {
            $status_dist[] = [
                'slug' => $slug,
                'label' => $label,
                'count' => $count
            ];
        }
    }

    // آخر 10 طلبات للمستخدم
    $recent_orders = [];
    $orders = wc_get_orders([
        'limit' => 10,
        'customer_id' => $user_id,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    foreach ($orders as $order) {
        $status = $order->get_meta('_crm_custom_status', true) ?: 'جديد';
        $recent_orders[] = [
            'id' => $order->get_id(),
            'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'status' => $statuses[$status] ?? $status,
            'total' => wc_price($order->get_total()),
            'date' => date('d/m/Y', strtotime($order->get_date_created()->date('Y-m-d H:i:s')))
        ];
    }

    car_crm_send_json_success([
        'completed_count' => $completed_count,
        'working_count' => $working_count,
        'pending_count' => $pending_count,
        'rejected_count' => $rejected_count,
        'status_dist' => $status_dist,
        'status_details' => $status_dist, // نفس البيانات لجدول التفاصيل
        'recent_orders' => $recent_orders
    ]);
});

/* =====================================================
   11. صفحة التقارير المالية
===================================================== */
function car_crm_financial_reports_page() {
    if (!current_user_can('view_crm_reports')) {
        wp_die('غير مصرح للوصول إلى التقارير المالية');
    }
    ?>
    <div class="crm-page container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <h1 class="fw-bold">💰 التقارير المالية</h1>
            <div class="text-muted"><?php echo date('Y-m-d'); ?></div>
        </div>

        <!-- ملخص المبيعات -->
        <div class="row g-4 mb-5">
            <div class="col-md-6">
                <div class="crm-card p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted mb-2 fw-bold">إجمالي الإيراد</h6>
                            <h2 class="fw-bold mb-0" id="total_revenue">...</h2>
                            <small class="text-muted">من الطلبات المكتملة فقط</small>
                        </div>
                        <div style="font-size: 2rem;">💵</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="crm-card p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="text-muted mb-2 fw-bold">إجمالي العمولات</h6>
                            <h2 class="fw-bold mb-0 text-success" id="total_commissions">...</h2>
                            <small class="text-muted">إجمالي العمولات المدفوعة</small>
                        </div>
                        <div style="font-size: 2rem;">📊</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- جدول الطلبات المكتملة -->
        <div class="crm-card p-4">
            <h5 class="fw-bold mb-4">📋 الطلبات المكتملة</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fw-bold">
                        <tr>
                            <th>رقم الطلب</th>
                            <th>العميل</th>
                            <th>النوع</th>
                            <th>المندوب</th>
                            <th>إجمالي الطلب</th>
                            <th>العمولة</th>
                            <th>التاريخ</th>
                        </tr>
                    </thead>
                    <tbody id="completedOrdersTable">
                        <tr><td colspan="7" class="text-center p-5"><div class="spinner-border text-primary"></div> جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $.post(CarCRM.ajax_url, {
            action: 'car_crm_get_financial_reports',
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success && res.data) {
                const data = res.data;

                // تحديث الملخص
                $('#total_revenue').html(data.total_revenue);
                $('#total_commissions').html(data.total_commissions);

                // ملء جدول الطلبات المكتملة
                if (data.completed_orders && data.completed_orders.length > 0) {
                    let html = '';
                    data.completed_orders.forEach(order => {
                        html += '<tr>';
                        html += '<td><strong>#' + order.id + '</strong></td>';
                        html += '<td>' + order.customer + '</td>';
                        html += '<td class="text-center">' + order.type_badge + '</td>';
                        html += '<td>' + order.salesman + '</td>';
                        html += '<td>' + order.total + '</td>';
                        html += '<td><span class="badge bg-success">' + order.commission + '</span></td>';
                        html += '<td><small class="text-muted">' + order.date + '</small></td>';
                        html += '</tr>';
                    });
                    $('#completedOrdersTable').html(html);
                } else {
                    $('#completedOrdersTable').html('<tr><td colspan="7" class="text-center text-muted p-5">لا توجد طلبات مكتملة</td></tr>');
                }
            }
        });
    });
    </script>
    <?php
}

/* =====================================================
   12. صفحة أداء المناديب
===================================================== */
function car_crm_salesmen_performance_page() {
    if (!current_user_can('view_crm_salesmen_performance')) {
        wp_die('غير مصرح للوصول إلى أداء المناديب');
    }
    ?>
    <div class="crm-page container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <h1 class="fw-bold">👥 أداء المناديب</h1>
            <div class="text-muted"><?php echo date('Y-m-d'); ?></div>
        </div>

        <!-- جدول أداء المناديب -->
        <div class="crm-card p-4 mb-5">
            <h5 class="fw-bold mb-4">📊 إحصائيات الأداء</h5>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light fw-bold">
                        <tr>
                            <th>المندوب</th>
                            <th class="text-center">عدد الطلبات المكتملة</th>
                            <th class="text-center">إجمالي الإيراد</th>
                            <th class="text-center">إجمالي العمولات</th>
                            <th class="text-center">متوسط العمولة</th>
                        </tr>
                    </thead>
                    <tbody id="salesmenPerformanceTable">
                        <tr><td colspan="5" class="text-center p-5"><div class="spinner-border text-primary"></div> جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- الرسم البياني -->
        <div class="crm-card p-4">
            <h5 class="fw-bold mb-4">📈 مقارنة الأداء</h5>
            <div style="height: 350px;">
                <canvas id="performanceChart"></canvas>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $.post(CarCRM.ajax_url, {
            action: 'car_crm_get_salesmen_performance',
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success && res.data) {
                const data = res.data;

                // ملء جدول الأداء
                if (data.salesmen && data.salesmen.length > 0) {
                    let html = '';
                    data.salesmen.forEach(salesman => {
                        html += '<tr>';
                        html += '<td><strong>' + salesman.name + '</strong></td>';
                        html += '<td class="text-center"><span class="badge bg-primary">' + salesman.completed_count + '</span></td>';
                        html += '<td class="text-center text-success fw-bold">' + salesman.total_revenue + '</td>';
                        html += '<td class="text-center"><span class="badge bg-success">' + salesman.total_commissions + '</span></td>';
                        html += '<td class="text-center text-muted">' + salesman.average_commission + '</td>';
                        html += '</tr>';
                    });
                    $('#salesmenPerformanceTable').html(html);
                } else {
                    $('#salesmenPerformanceTable').html('<tr><td colspan="5" class="text-center text-muted p-5">لا توجد بيانات</td></tr>');
                }

                // رسم البياني
                const names = data.salesmen.map(s => s.name);
                const revenues = data.salesmen.map(s => parseFloat(s.total_revenue.replace(/[^0-9.-]/g, '')) || 0);
                const commissions = data.salesmen.map(s => parseFloat(s.total_commissions.replace(/[^0-9.-]/g, '')) || 0);

                new Chart(document.getElementById('performanceChart'), {
                    type: 'bar',
                    data: {
                        labels: names,
                        datasets: [
                            {
                                label: 'الإيراد الكلي',
                                data: revenues,
                                backgroundColor: '#0d6efd',
                                borderRadius: 8
                            },
                            {
                                label: 'العمولات',
                                data: commissions,
                                backgroundColor: '#20c997',
                                borderRadius: 8
                            }
                        ]
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }
        });
    });
    </script>
    <?php
}

/* =====================================================
   13. صفحة الرسوم البيانية والتقارير البصرية
===================================================== */
function car_crm_analytics_page() {
    if (!current_user_can('view_crm_analytics')) {
        wp_die('غير مصرح للوصول إلى الرسوم البيانية');
    }
    ?>
    <div class="crm-page container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <h1 class="fw-bold">📊 الرسوم البيانية والتقارير</h1>
            <div class="text-muted"><?php echo date('Y-m-d'); ?></div>
        </div>

        <div class="row g-4">
            <!-- نمو الإيراد -->
            <div class="col-lg-12">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">📈 نمو الإيراد عبر الزمن</h5>
                    <div style="height: 350px;">
                        <canvas id="revenueGrowthChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- توزيع الحالات -->
            <div class="col-lg-6">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">🎯 توزيع حالات الطلبات</h5>
                    <div style="height: 350px;">
                        <canvas id="statusDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- مقارنة أداء المناديب -->
            <div class="col-lg-6">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">🏆 مقارنة أداء المناديب</h5>
                    <div style="height: 350px;">
                        <canvas id="salesmanComparisonChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- توزيع الأنواع -->
            <div class="col-lg-6">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">👤 توزيع الأفراد والشركات</h5>
                    <div style="height: 300px;">
                        <canvas id="typeDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- الإحصائيات السريعة -->
            <div class="col-lg-6">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-4">⚡ إحصائيات سريعة</h5>
                    <div class="row g-3">
                        <div class="col-6">
                            <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                                <div class="text-muted small">إجمالي الطلبات</div>
                                <div class="h4 fw-bold" id="stat_total_orders">...</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                                <div class="text-muted small">الطلبات المكتملة</div>
                                <div class="h4 fw-bold text-success" id="stat_completed">...</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                                <div class="text-muted small">معدل الإكمال</div>
                                <div class="h4 fw-bold text-info" id="stat_completion_rate">...</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div style="padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                                <div class="text-muted small">عدد المناديب</div>
                                <div class="h4 fw-bold text-primary" id="stat_salesmen_count">...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $.post(CarCRM.ajax_url, {
            action: 'car_crm_get_analytics_data',
            nonce: CarCRM.nonce
        }, function(res) {
            if (res.success && res.data) {
                const data = res.data;

                // تحديث الإحصائيات السريعة
                $('#stat_total_orders').text(data.total_orders);
                $('#stat_completed').text(data.completed_orders);
                $('#stat_completion_rate').text(data.completion_rate + '%');
                $('#stat_salesmen_count').text(data.salesmen_count);

                // نمو الإيراد - رسم بياني خطي
                new Chart(document.getElementById('revenueGrowthChart'), {
                    type: 'line',
                    data: {
                        labels: data.revenue_growth.labels,
                        datasets: [{
                            label: 'الإيراد اليومي',
                            data: data.revenue_growth.data,
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 5,
                            pointBackgroundColor: '#0d6efd'
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: { legend: { display: true } },
                        scales: { y: { beginAtZero: true } }
                    }
                });

                // توزيع الحالات - رسم بياني دائري
                new Chart(document.getElementById('statusDistributionChart'), {
                    type: 'doughnut',
                    data: {
                        labels: data.status_distribution.labels,
                        datasets: [{
                            data: data.status_distribution.data,
                            backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#0dcaf0', '#6c757d', '#dc3545', '#6610f2', '#fd7e14', '#20c997', '#0dcaf0', '#868e96', '#e83e8c', '#17a2b8']
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });

                // مقارنة أداء المناديب - رسم بياني عمودي
                new Chart(document.getElementById('salesmanComparisonChart'), {
                    type: 'bar',
                    data: {
                        labels: data.salesman_performance.labels,
                        datasets: [{
                            label: 'عدد الطلبات المكتملة',
                            data: data.salesman_performance.data,
                            backgroundColor: '#0d6efd',
                            borderRadius: 10
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        scales: { y: { beginAtZero: true } }
                    }
                });

                // توزيع الأنواع - رسم بياني دائري
                new Chart(document.getElementById('typeDistributionChart'), {
                    type: 'pie',
                    data: {
                        labels: ['الأفراد', 'الشركات'],
                        datasets: [{
                            data: [data.individual_count, data.company_count],
                            backgroundColor: ['#0dcaf0', '#198754'],
                            borderColor: ['#fff', '#fff'],
                            borderWidth: 2
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            }
        });
    });
    </script>
    <?php
}

/* =====================================================
   14. صفحة إدارة الصلاحيات (بسيطة)
===================================================== */
function car_crm_roles_page() {
    if (!current_user_can('manage_crm_roles')) {
        wp_die('غير مصرح للوصول إلى إدارة الصلاحيات');
    }
    ?>
    <div class="crm-page container-fluid">
        <h1 class="fw-bold mb-5">🔐 إدارة الصلاحيات والأدوار</h1>

        <div class="row g-4">
            <!-- دور Administrator -->
            <div class="col-lg-4">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-3" style="color: #dc3545;">👨‍💼 Administrator</h5>
                    <p class="text-muted mb-3"><strong>الوصول الكامل:</strong></p>
                    <ul class="list-unstyled">
                        <li><span class="badge bg-success me-2">✓</span> الداشبورد</li>
                        <li><span class="badge bg-success me-2">✓</span> جميع الطلبات</li>
                        <li><span class="badge bg-success me-2">✓</span> طلباتي</li>
                        <li><span class="badge bg-success me-2">✓</span> التقارير المالية</li>
                        <li><span class="badge bg-success me-2">✓</span> أداء المناديب</li>
                        <li><span class="badge bg-success me-2">✓</span> الرسوم البيانية</li>
                        <li><span class="badge bg-success me-2">✓</span> إدارة الصلاحيات</li>
                        <li><span class="badge bg-success me-2">✓</span> تعيين المناديب</li>
                        <li><span class="badge bg-success me-2">✓</span> تحديث البيانات</li>
                    </ul>
                </div>
            </div>

            <!-- دور Shop Manager -->
            <div class="col-lg-4">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-3" style="color: #0d6efd;">📊 Shop Manager</h5>
                    <p class="text-muted mb-3"><strong>صلاحيات مدير:</strong></p>
                    <ul class="list-unstyled">
                        <li><span class="badge bg-success me-2">✓</span> الداشبورد</li>
                        <li><span class="badge bg-success me-2">✓</span> جميع الطلبات</li>
                        <li><span class="badge bg-success me-2">✓</span> طلباتي</li>
                        <li><span class="badge bg-danger me-2">✗</span> التقارير المالية</li>
                        <li><span class="badge bg-success me-2">✓</span> أداء المناديب</li>
                        <li><span class="badge bg-success me-2">✓</span> الرسوم البيانية</li>
                        <li><span class="badge bg-danger me-2">✗</span> إدارة الصلاحيات</li>
                        <li><span class="badge bg-success me-2">✓</span> تعيين المناديب</li>
                        <li><span class="badge bg-success me-2">✓</span> تحديث البيانات</li>
                    </ul>
                </div>
            </div>

            <!-- دور Contributor -->
            <div class="col-lg-4">
                <div class="crm-card p-4">
                    <h5 class="fw-bold mb-3" style="color: #198754;">👤 Contributor</h5>
                    <p class="text-muted mb-3"><strong>صلاحيات محدودة:</strong></p>
                    <ul class="list-unstyled">
                        <li><span class="badge bg-danger me-2">✗</span> الداشبورد</li>
                        <li><span class="badge bg-danger me-2">✗</span> جميع الطلبات</li>
                        <li><span class="badge bg-success me-2">✓</span> طلباتي فقط</li>
                        <li><span class="badge bg-danger me-2">✗</span> التقارير المالية</li>
                        <li><span class="badge bg-danger me-2">✗</span> أداء المناديب</li>
                        <li><span class="badge bg-danger me-2">✗</span> الرسوم البيانية</li>
                        <li><span class="badge bg-danger me-2">✗</span> إدارة الصلاحيات</li>
                        <li><span class="badge bg-danger me-2">✗</span> تعيين المناديب</li>
                        <li><span class="badge bg-success me-2">✓</span> تحديث طلباته</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- إرشادات -->
        <div class="row g-4 mt-3">
            <div class="col-12">
                <div class="crm-card p-4 bg-light">
                    <h5 class="fw-bold mb-3">📋 كيفية إدارة الأدوار</h5>
                    <p>يمكنك إدارة أدوار المستخدمين من خلال:</p>
                    <ol>
                        <li>اذهب إلى <strong>لوحة التحكم → المستخدمون</strong></li>
                        <li>اختر المستخدم الذي تريد تعديل دوره</li>
                        <li>غيّر الدور من القائمة المنسدلة في قسم "Role"</li>
                        <li>احفظ التغييرات</li>
                    </ol>
                    <div class="alert alert-info mt-3 mb-0">
                        <strong>ملاحظة:</strong> يجب أن تكون مسؤول الموقع (Administrator) لتتمكن من تغيير أدوار المستخدمين.
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}







/* =====================================================
   14. Financial Reports AJAX Handler
===================================================== */
add_action('wp_ajax_car_crm_get_financial_reports', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    if (!current_user_can('view_crm_reports')) wp_send_json_error('غير مصرح');

    // جلب الطلبات المكتملة فقط
    $args = [
        'limit' => 500,
        'orderby' => 'date',
        'order' => 'DESC',
        'status' => 'any',
        'meta_query' => [
            ['key' => '_crm_custom_status', 'value' => 'completed']
        ]
    ];

    $orders = wc_get_orders($args);
    $total_revenue = 0;
    $total_commissions = 0;
    $completed_orders = [];

    foreach ($orders as $order) {
        $id = $order->get_id();
        $commission = floatval($order->get_meta('crm_a', true) ?: 0);
        $total = floatval($order->get_total());

        // أضف الإيراد والعمولة
        $total_revenue += $total;
        if ($commission > 0) {
            $total_commissions += $commission;
        }

        // جهز بيانات الطلب
        $salesman_id = $order->get_meta('_crm_salesman_id', true);
        $salesman_name = $salesman_id ? get_userdata($salesman_id)->display_name : 'غير مسند';

        $raw_type = $order->get_meta('_crm_order_type', true) ?: $order->get_meta('order_type', true) ?: 'individual';
        if (in_array(strtolower($raw_type), ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'])) {
            $type_badge = '<span class="badge bg-success">شركة</span>';
        } else {
            $type_badge = '<span class="badge bg-info">فرد</span>';
        }

        $completed_orders[] = [
            'id' => $id,
            'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'type_badge' => $type_badge,
            'salesman' => $salesman_name,
            'total' => wc_price($total),
            'commission' => wc_price($commission),
            'date' => $order->get_date_created()->format('Y-m-d')
        ];
    }

    car_crm_send_json_success([
        'total_revenue_raw' => $total_revenue,
        'total_commissions_raw' => $total_commissions,
        'total_revenue' => wc_price($total_revenue),
        'total_commissions' => wc_price($total_commissions),
        'completed_orders' => $completed_orders
    ]);
});

/* =====================================================
   15. Salesmen Performance AJAX Handler
===================================================== */
add_action('wp_ajax_car_crm_get_salesmen_performance', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    if (!current_user_can('view_crm_reports')) wp_send_json_error('غير مصرح');

    global $wpdb;

    // التحقق من تفعيل الـ HPOS
    $using_hpos = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
        $using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    $order_table = $wpdb->prefix . ($using_hpos ? 'wc_orders' : 'posts');
    $meta_table  = $wpdb->prefix . ($using_hpos ? 'wc_orders_meta' : 'postmeta');
    $id_col      = $using_hpos ? 'id' : 'ID';
    $type_col    = $using_hpos ? 'type' : 'post_type';
    $status_col  = $using_hpos ? 'status' : 'post_status';
    $meta_id_col = $using_hpos ? 'order_id' : 'post_id';

    $salesmen = get_users(['role__in' => ['car_crm_salesman', 'administrator', 'editor']]);
    $salesmen_data = [];

    foreach ($salesmen as $salesman) {
        // جلب الطلبات المكتملة للمندوب
        $args = [
            'limit' => 500,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_crm_salesman_id', 'value' => (string)$salesman->ID],
                ['key' => '_crm_custom_status', 'value' => 'completed']
            ]
        ];

        $orders = wc_get_orders($args);

        if (empty($orders)) continue;

        $completed_count = count($orders);
        $total_revenue = 0;
        $total_commissions = 0;

        foreach ($orders as $order) {
            $total_revenue += floatval($order->get_total());
            $commission = floatval($order->get_meta('crm_a', true) ?: 0);
            if ($commission > 0) {
                $total_commissions += $commission;
            }
        }

        $average_commission = $completed_count > 0 ? $total_commissions / $completed_count : 0;

        $salesmen_data[] = [
            'name' => $salesman->display_name,
            'completed_count' => $completed_count,
            'total_revenue' => wc_price($total_revenue),
            'total_commissions' => wc_price($total_commissions),
            'average_commission' => wc_price($average_commission)
        ];
    }

    car_crm_send_json_success([
        'salesmen' => $salesmen_data
    ]);
});

/* =====================================================
   16. Analytics Data AJAX Handler
===================================================== */
add_action('wp_ajax_car_crm_get_analytics_data', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    if (!current_user_can('view_crm_reports')) wp_send_json_error('غير مصرح');

    global $wpdb;

    // التحقق من تفعيل الـ HPOS
    $using_hpos = false;
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
        $using_hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    // جلب جميع الطلبات
    $all_orders = wc_get_orders(['limit' => 1000, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'any']);
    $total_orders = count($all_orders);
    $completed_orders = 0;
    $individual_count = 0;
    $company_count = 0;
    $daily_revenue = [];
    $statuses = car_crm_get_statuses();
    $status_dist = array_fill_keys(array_keys($statuses), 0);
    $salesman_completed = [];

    foreach ($all_orders as $order) {
        $status = $order->get_meta('_crm_custom_status', true) ?: 'new';
        $status_dist[$status] = ($status_dist[$status] ?? 0) + 1;

        if ($status === 'completed') {
            $completed_orders++;
        }

        $raw_type = $order->get_meta('_crm_order_type', true) ?: $order->get_meta('order_type', true) ?: 'individual';
        if (in_array(strtolower($raw_type), ['company', 'sharika', 'sharikat', 'sh', 'شركات', 'شركة'])) {
            $company_count++;
        } else {
            $individual_count++;
        }

        // حساب نمو الإيراد اليومي
        if ($status === 'completed') {
            $date = $order->get_date_created()->format('Y-m-d');
            if (!isset($daily_revenue[$date])) {
                $daily_revenue[$date] = 0;
            }
            $daily_revenue[$date] += floatval($order->get_total());
        }

        // أداء المناديب
        $salesman_id = $order->get_meta('_crm_salesman_id', true);
        if ($salesman_id && $status === 'completed') {
            if (!isset($salesman_completed[$salesman_id])) {
                $user = get_userdata($salesman_id);
                $salesman_completed[$salesman_id] = [
                    'name' => $user ? $user->display_name : 'Unknown',
                    'count' => 0
                ];
            }
            $salesman_completed[$salesman_id]['count']++;
        }
    }

    // ترتيب البيانات اليومية والحصول على آخر 30 يوم
    ksort($daily_revenue);
    $daily_revenue = array_slice($daily_revenue, -30, 30, true);

    $revenue_growth_labels = array_keys($daily_revenue);
    $revenue_growth_data = array_values($daily_revenue);

    // إحصائيات الحالات
    $status_dist_labels = [];
    $status_dist_data = [];
    foreach ($statuses as $slug => $label) {
        $count = $status_dist[$slug] ?? 0;
        if ($count > 0) {
            $status_dist_labels[] = $label;
            $status_dist_data[] = $count;
        }
    }

    // أداء المناديب - أكثر 5 مناديب نشاطاً
    usort($salesman_completed, function($a, $b) {
        return $b['count'] - $a['count'];
    });
    $top_salesmen = array_slice($salesman_completed, 0, 5);

    $salesman_perf_labels = array_map(function($s) { return $s['name']; }, $top_salesmen);
    $salesman_perf_data = array_map(function($s) { return $s['count']; }, $top_salesmen);

    $completion_rate = $total_orders > 0 ? round(($completed_orders / $total_orders) * 100) : 0;

    car_crm_send_json_success([
        'total_orders' => $total_orders,
        'completed_orders' => $completed_orders,
        'completion_rate' => $completion_rate,
        'individual_count' => $individual_count,
        'company_count' => $company_count,
        'salesmen_count' => count(get_users(['role__in' => ['car_crm_salesman', 'administrator', 'editor']])),
        'revenue_growth' => [
            'labels' => $revenue_growth_labels,
            'data' => $revenue_growth_data
        ],
        'status_distribution' => [
            'labels' => $status_dist_labels,
            'data' => $status_dist_data
        ],
        'salesman_performance' => [
            'labels' => $salesman_perf_labels,
            'data' => $salesman_perf_data
        ]
    ]);
});

// === Debug Meta Data to Console via AJAX ===
add_action('wp_ajax_car_crm_debug_meta', function() {
    check_ajax_referer('car_crm_nonce', 'nonce');
    
    // غيّر الرقم إلى ID حقيقي لطلب عندك
    $order_id = intval($_POST['order_id'] ?? 35051);
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('غير مصرح');
    }

    $meta = get_post_meta($order_id);
    
    // تنظيف القيم عشان تظهر نظيفة في الكونسول
    $clean_meta = [];
    foreach ($meta as $key => $value) {
        $clean_meta[$key] = is_array($value) ? $value[0] : $value;
    }

    error_log('CRM Debug Meta for Order #' . $order_id . ': ' . print_r($clean_meta, true));
    
    wp_send_json_success($clean_meta);
});