<?php
# Child Theme 
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_style' );
				function hello_elementor_child_style() {
					wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
					wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array('parent-style') );
				}


# Name Outputter
function display_user_name() {
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        return esc_html($current_user->user_firstname);
    } else {
        return 'Guest';
    }
}

function register_user_name_shortcode() {
    add_shortcode('user_name', 'display_user_name');
}
add_action('init', 'register_user_name_shortcode');

# Redirection and Login Rules
 function custom_login_redirect() {
    if (!is_user_logged_in()) {
        $login_page = home_url('/login/');
        if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false && $_SERVER['REQUEST_METHOD'] == 'GET') {
            wp_redirect($login_page);
            exit;
        }
    } else {
        $user = wp_get_current_user();
        if (in_array('administrator', (array) $user->roles)) {
            return; 
        }

        $dashboard_page = home_url('/dashboard/');
        if (strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false && $_SERVER['REQUEST_METHOD'] == 'GET') {
            wp_redirect($dashboard_page);
            exit;
        } elseif (is_front_page()) { 
            wp_redirect($dashboard_page);
            exit;
        }
    }
}
add_action('template_redirect', 'custom_login_redirect');
add_action('check_admin_referer', 'logout_without_confirm', 10, 2);

function logout_without_confirm($action, $result)
{

    if ($action == "log-out" && !isset($_GET['_wpnonce'])) {
        $redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : '/';
        $location = str_replace('&amp;', '&', wp_logout_url($redirect_to));
        header("Location: $location");
        die;
    }
}

# Arduino Ajax Calls to Log Data to Database
add_action('wp_ajax_receive_data_from_arduino', 'receive_data_from_arduino');
add_action('wp_ajax_nopriv_receive_data_from_arduino', 'receive_data_from_arduino');

function receive_data_from_arduino() {

    $received_value = $_POST['value'];
    $appliance_number = $_POST['appliance'];

    global $wpdb;

    $data = array(
        'value' => $received_value,
        'appliance_no' => $appliance_number, 
        'received_at' => current_time('mysql') 
    );

    $wpdb->insert('wp_arduino_data', $data);

    if ($wpdb->last_error) {
        echo json_encode(array('error' => 'Failed to insert data into the database.'));
    } else {
        echo json_encode(array('value' => $received_value));
    }

    wp_die();
}

function get_readable_appliance_type($type) {
    $types = array(
        'washing_machine' => 'Washing Machine',
        'sink' => 'Sink',
        'shower' => 'Shower',
        'outdoor_tap' => 'Outdoor Tap',
        'bath' => 'Bath'
    );

    return isset($types[$type]) ? $types[$type] : $type;
}

# Device Assignment
function device_assignment_form() {
    // Check if user is logged in
    if (!is_user_logged_in()) {
        return 'You need to be logged in to assign a device.';
    }

    // Handle form submission
    if (isset($_POST['device_assignment_submit'])) {
        return handle_device_assignment();
    }

    // Form HTML
    ob_start();
    ?>
    <form style="display:flex; flex-direction:column; flex-wrap:wrap; gap:10px;"method="post">
        <label for="appliance_number">Appliance Number:</label>
        <input type="text" id="appliance_number" name="appliance_number" required>
        
        <label for="appliance_type">Appliance Type:</label>
        <select id="appliance_type" name="appliance_type" required>
            <option value="washing_machine">Washing Machine</option>
            <option value="sink">Sink</option>
            <option value="shower">Shower</option>
            <option value="outdoor_tap">Outdoor Tap</option>
            <option value="bath">Bath</option>
        </select>
        
        <label for="nicename">Nickname:</label>
        <input type="text" id="nicename" name="nicename" required>

        <input type="submit" name="device_assignment_submit" value="Assign Device">
    </form>
    <?php
    return ob_get_clean();
}

function handle_device_assignment() {
    global $wpdb;

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Get the submitted appliance number
    
    $appliance_number = sanitize_text_field($_POST['appliance_number']);
    $appliance_type = sanitize_text_field($_POST['appliance_type']);
    $nicename = sanitize_text_field($_POST['nicename']);


 // Check if appliance_number is already assigned to the user
 $existing_entry = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM wp_devices WHERE appliance_number = %s AND user_id = %d",
    $appliance_number, $user_id
));

if ($existing_entry > 0) {
    return 'This device is already assigned to you.';
}

$data = array(
    'appliance_number' => $appliance_number,
    'appliance_type' => $appliance_type,
    'nicename' => $nicename,
    'user_id' => $user_id
);
    $wpdb->insert('wp_devices', $data);

    if ($wpdb->last_error) {
        return 'Failed to assign the device. Please try again.';
    } else {
        return 'Device assigned successfully!';
    }
}

function register_device_assignment_shortcode() {
    add_shortcode('device_assignment_form', 'device_assignment_form');
}
add_action('init', 'register_device_assignment_shortcode');

function is_device_on($appliance_number) {
    global $wpdb;

    $one_hour_ago = date('Y-m-d H:i:s', strtotime('-1 hour'));

    $last_message = $wpdb->get_var($wpdb->prepare(
        "SELECT received_at FROM wp_arduino_data WHERE appliance_no = %s AND received_at >= %s ORDER BY received_at DESC LIMIT 1",
        $appliance_number, $one_hour_ago
    ));

    return !empty($last_message);
}

# Display Assigned Devices
function display_user_devices() {
    // Check if the user is logged in
    if (!is_user_logged_in()) {
        return 'You need to be logged in to view your devices.';
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    global $wpdb;

    // Retrieve the user's devices from the database
    $devices = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM wp_devices WHERE user_id = %d",
        $user_id
    ));

    // If no devices found
    if (empty($devices)) {
        return '<p> No devices found, please register a device to get started! </p>';
    }

    // Start output buffering
    ob_start();
    ?>

    <table style="font-family:'Montserrat';">
        <thead>
            <tr>
                <th>Device Name</th>
                <th>Device Number</th>
                <th>Device Type</th>
                <th>Device Status</th>
                <th style="width:100px;"></th>
                <th style="width:100px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($devices as $device) : ?>
                <tr>
                    <td><?php echo esc_html($device->nicename); ?></td>
                    <td><?php echo esc_html($device->appliance_number); ?></td>
                    <td><?php echo esc_html(get_readable_appliance_type($device->appliance_type)); ?></td>
                    <td>
                        <?php if (is_device_on($device->appliance_number)) : ?>
                            <span style="color: green; ">Connected</span>
                        <?php else : ?>
                            <span style="color: red; ">Disconnected</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="download-data" data-device-number="<?php echo esc_attr($device->appliance_number); ?>">Download Appliance Data (CSV)</button>
                    </td>
                    <td>
                        <button class="remove-device" data-device-id="<?php echo esc_attr($device->id); ?>">Remove Device</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle device removal
            document.querySelectorAll('.remove-device').forEach(function(button) {
                button.addEventListener('click', function() {
                    var deviceId = this.getAttribute('data-device-id');
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>');
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            alert('Device removed successfully');
                            location.reload();
                        } else {
                            alert('Error removing device');
                        }
                    };
                    xhr.send('action=remove_device&device_id=' + deviceId);
                });
            });

            // Handle data download
            document.querySelectorAll('.download-data').forEach(function(button) {
                button.addEventListener('click', function() {
                    var deviceNumber = this.getAttribute('data-device-number');
                    window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=download_device_data&device_number=' + deviceNumber;
                });
            });
        });
    </script>

    <?php
    return ob_get_clean();
}

function register_user_devices_shortcode() {
    add_shortcode('user_devices', 'display_user_devices');
}
add_action('init', 'register_user_devices_shortcode');

# Device Remover
function handle_remove_device() {
    global $wpdb;

    // Check if the user is logged in
    if (!is_user_logged_in()) {
        wp_send_json_error('You need to be logged in to remove a device.');
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Get the device ID from the POST request
    $device_id = intval($_POST['device_id']);

    // Check if the device belongs to the current user
    $device = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM wp_devices WHERE id = %d AND user_id = %d",
        $device_id, $user_id
    ));

    if (!$device) {
        wp_send_json_error('Invalid device.');
    }

    // Remove the device
    $wpdb->delete('wp_devices', array('id' => $device_id));

    if ($wpdb->last_error) {
        wp_send_json_error('Failed to remove the device.');
    } else {
        wp_send_json_success('Device removed successfully.');
    }
}

add_action('wp_ajax_remove_device', 'handle_remove_device');

# Device Downloader
function handle_download_device_data() {
    global $wpdb;

    // Check if the user is logged in
    if (!is_user_logged_in()) {
        wp_die('You need to be logged in to download device data.');
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Get the device number from the GET request
    $device_number = sanitize_text_field($_GET['device_number']);

    // Check if the device belongs to the current user
    $device = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM wp_devices WHERE appliance_number = %s AND user_id = %d",
        $device_number, $user_id
    ));

    if (!$device) {
        wp_die('Invalid device.');
    }

    // Retrieve the device data from the database
    $data = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM wp_arduino_data WHERE appliance_no = %s",
        $device_number
    ), ARRAY_A);

    if (empty($data)) {
        wp_die('No data found for this device.');
    }

    // Prepare CSV file
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="device_data.csv"');
    $output = fopen('php://output', 'w');

    // Output column headers
    fputcsv($output, array('ID', 'Value', 'Appliance Number', 'Received At'));

    // Output rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

add_action('wp_ajax_download_device_data', 'handle_download_device_data');
add_action('wp_ajax_nopriv_download_device_data', 'handle_download_device_data');


# User Data Downloader
// Function to handle downloading all user data as a CSV
function handle_download_all_user_data() {
    global $wpdb;

    // Check if the user is logged in
    if (!is_user_logged_in()) {
        wp_die('You need to be logged in to download data.');
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Retrieve all appliances related to the current user
    $devices = $wpdb->get_results($wpdb->prepare(
        "SELECT appliance_number FROM wp_devices WHERE user_id = %d",
        $user_id
    ), ARRAY_A);

    if (empty($devices)) {
        wp_die('No devices found for this user.');
    }

    // Prepare an array to hold all appliance numbers
    $appliance_numbers = array_map(function($device) {
        return $device['appliance_number'];
    }, $devices);

    // Retrieve data for all appliances related to the current user
    $data = $wpdb->get_results("SELECT * FROM wp_arduino_data WHERE appliance_no IN ('" . implode("','", $appliance_numbers) . "')", ARRAY_A);

    if (empty($data)) {
        wp_die('No data found for the user\'s devices.');
    }

    // Prepare CSV file
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="user_data.csv"');
    $output = fopen('php://output', 'w');

    // Output column headers
    fputcsv($output, array('ID', 'Value', 'Appliance Number', 'Received At'));

    // Output rows
    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

add_action('wp_ajax_download_all_user_data', 'handle_download_all_user_data');
add_action('wp_ajax_nopriv_download_all_user_data', 'handle_download_all_user_data');

function download_all_user_data_button() {
    // Check if the user is logged in
    if (!is_user_logged_in()) {
        return 'You need to be logged in to download your data.';
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    global $wpdb;

    // Check if the user has any registered appliances
    $devices = $wpdb->get_results($wpdb->prepare(
        "SELECT appliance_number FROM wp_devices WHERE user_id = %d",
        $user_id
    ));

    // If no devices found, return an empty string to hide the button
    if (empty($devices)) {
        return '';
    }

    // Output the download button
    ob_start();
    ?>
    <button id="download-all-user-data">Download All Data</button>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('download-all-user-data').addEventListener('click', function() {
                window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=download_all_user_data';
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

function register_download_all_user_data_shortcode() {
    add_shortcode('download_all_user_data', 'download_all_user_data_button');
}

add_action('init', 'register_download_all_user_data_shortcode');


# Water Usage by Appliance
// Function to fetch all appliance types registered to the current user
function fetch_registered_appliance_types() {
    // Check if the user is logged in
    if (!is_user_logged_in()) {
        return array(); // Return empty data if not logged in
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Connect to the database
    global $wpdb;

    // Query to retrieve all appliance types registered to the current user
    $query = $wpdb->prepare("
        SELECT DISTINCT appliance_type 
        FROM wp_devices 
        WHERE user_id = %d
    ", $user_id);

    return $wpdb->get_col($query);
}

// Function to fetch water usage data for the current user and the current month
function fetch_water_usage_data() {
    // Check if the user is logged in
    if (!is_user_logged_in()) {
        return array(); // Return empty data if not logged in
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Connect to the database
    global $wpdb;

    // Get the start of the current month in the WordPress timezone
    $start_of_month = date('Y-m-01 00:00:00', current_time('timestamp'));

    // Query to retrieve water usage data for the current month
    $query = $wpdb->prepare("
        SELECT SUM(ad.value) AS total_usage, d.appliance_type 
        FROM wp_arduino_data ad
        INNER JOIN wp_devices d ON ad.appliance_no = d.appliance_number
        WHERE ad.received_at >= %s AND d.user_id = %d
        GROUP BY d.appliance_type
    ", $start_of_month, $user_id);

    return $wpdb->get_results($query);
}

// Function to calculate water usage percentages
function calculate_water_usage_percentages() {
    // Fetch registered appliance types
    $registered_appliances = fetch_registered_appliance_types();
    
    // Fetch water usage data
    $usage_data = fetch_water_usage_data();

    // Initialize variables to store total usage and individual appliance usage
    $total_usage = 0;
    $appliance_usage = array_fill_keys($registered_appliances, 0);

    // Iterate through the results to calculate total usage and individual appliance usage
    foreach ($usage_data as $data_point) {
        $total_usage += $data_point->total_usage;
        $appliance_usage[$data_point->appliance_type] = $data_point->total_usage;
    }

    // Calculate percentages
    $percentages = array();
    foreach ($appliance_usage as $appliance_type => $usage) {
        $percentage = ($total_usage > 0) ? ($usage / $total_usage) * 100 : 0;
        $percentages[$appliance_type] = $percentage;
    }

    return $percentages;
}

// Function to generate the output HTML for water usage percentages
// Function to generate the output HTML for water usage percentages
function generate_water_usage_percentages_output($percentages) {
    $appliance_names = array(
        'washing_machine' => 'Washing Machine',
        'sink' => 'Sink',
        'shower' => 'Shower',
        'outdoor_tap' => 'Outdoor Tap',
        'bath' => 'Bath'
    );

    $appliance_icons = array(
        'washing_machine' => 'fa-solid fa-tshirt', // Replace with appropriate icon
        'sink' => 'fa-solid fa-sink',
        'shower' => 'fa-solid fa-shower',
        'outdoor_tap' => 'fa-solid fa-faucet',
        'bath' => 'fa-solid fa-bath'
    );

    $output = '';
    foreach ($percentages as $appliance_type => $percentage) {
        $appliance_name = isset($appliance_names[$appliance_type]) ? $appliance_names[$appliance_type] : 'Appliance ' . $appliance_type;
        $icon_class = isset($appliance_icons[$appliance_type]) ? $appliance_icons[$appliance_type] : 'fa-solid fa-question-circle'; // Default icon
        $output .= "<p class='appliances'><i class='$icon_class'></i> $appliance_name: <span class='percentage' data-appliance='$appliance_type'>" . number_format($percentage, 2) . "%</span></p><br>";
    }
    return $output;
}

// Function to update water usage percentages via AJAX
function update_water_usage_percentages() {
    // Fetch and calculate water usage percentages
    $percentages = calculate_water_usage_percentages();

    // Return JSON response with updated percentages
    wp_send_json(array("percentages" => $percentages));
}

// Register AJAX action
add_action('wp_ajax_update_water_usage_percentages', 'update_water_usage_percentages');
add_action('wp_ajax_nopriv_update_water_usage_percentages', 'update_water_usage_percentages');

// Function to output water usage percentages shortcode
function water_usage_percentages_shortcode() {
    // Calculate water usage percentages
    $percentages = calculate_water_usage_percentages();

    // Generate HTML output
    $output = generate_water_usage_percentages_output($percentages);

    // Add JavaScript for real-time updating
    $output .= "<script>";
    $output .= "function updateWaterUsagePercentages() {";
    $output .= "    var xhr = new XMLHttpRequest();";
    $output .= "    xhr.onreadystatechange = function() {";
    $output .= "        if (xhr.readyState === XMLHttpRequest.DONE) {";
    $output .= "            if (xhr.status === 200) {";
    $output .= "                var responseData = JSON.parse(xhr.responseText);";
    $output .= "                var percentages = responseData.percentages;";
    $output .= "                var elements = document.querySelectorAll('.percentage');";
    $output .= "                elements.forEach(function(element) {";
    $output .= "                    var applianceType = element.getAttribute('data-appliance');";
    $output .= "                    if (percentages.hasOwnProperty(applianceType)) {";
    $output .= "                        element.textContent = percentages[applianceType].toFixed(2) + '%';";
    $output .= "                    }";
    $output .= "                });";
    $output .= "            } else {";
    $output .= "                console.error('Error: ' + xhr.status);";
    $output .= "            }";
    $output .= "        }";
    $output .= "    };";
    $output .= "    xhr.open('GET', '" . admin_url('admin-ajax.php') . "?action=update_water_usage_percentages', true);";
    $output .= "    xhr.send();";
    $output .= "}";
    $output .= "setInterval(updateWaterUsagePercentages, 10000);"; // Update every 10 seconds
    $output .= "updateWaterUsagePercentages();"; // Initial call to update immediately
    $output .= "</script>";

    return $output;
}

// Register shortcode
add_shortcode('water_usage_percentages', 'water_usage_percentages_shortcode');

# Daily, Monthly and Yearly Charts
// Function to fetch water data based on the specified period
function fetch_water_data($period) {
    global $wpdb;

    // Check if the user is logged in
    if (!is_user_logged_in()) {
        return array(); // Return empty data if not logged in
    }

    // Get the current user
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Get the WordPress timezone offset in seconds
    $timezone_offset_seconds = get_option('gmt_offset') * 3600;

    // Initialize variables for different periods
    switch ($period) {
        case 'hourly':
            $date_format = 'Y-m-d H:00:00';
            $start_time = strtotime('today');
            $group_by = 'HOUR';
            $data_points = 24;
            break;
        case 'daily':
            $date_format = 'Y-m-d 00:00:00';
            $start_time = strtotime('first day of this month');
            $group_by = 'DAY';
            $data_points = date('t'); // Number of days in the current month
            break;
        case 'monthly':
            $date_format = 'Y-m-01 00:00:00';
            $start_time = strtotime('first day of January this year');
            $group_by = 'MONTH';
            $data_points = 12;
            break;
        case 'yearly':
            $date_format = 'Y-01-01 00:00:00';
            $start_time = strtotime('first day of January this year');
            $group_by = 'YEAR';
            $data_points = 1; // Only one data point for the year
            break;
        default:
            return array(); // Invalid period
    }

    // Calculate the start time in the database timezone
    $start_time_db = date('Y-m-d H:i:s', $start_time + $timezone_offset_seconds);

    // Prepare an array to hold data points
    $data = array_fill(0, $data_points, 0);

    // Query to retrieve water usage data for the specified period
    $query = $wpdb->prepare("
        SELECT SUM(ad.value) AS total_usage, $group_by(ad.received_at) AS time_unit
        FROM wp_arduino_data ad
        INNER JOIN wp_devices d ON ad.appliance_no = d.appliance_number
        WHERE ad.received_at >= %s AND d.user_id = %d
        GROUP BY $group_by(ad.received_at)
    ", $start_time_db, $user_id);

    $water_data = $wpdb->get_results($query);

    // Fill in the data array with values from the database
    foreach ($water_data as $data_point) {
        $index = (int)$data_point->time_unit;
        $data[$index] = $data_point->total_usage;
    }

    return $data;
}

function output_water_usage_chart_shortcode($atts) {
    $atts = shortcode_atts(array(
        'period' => 'daily' // Default to daily
    ), $atts, 'water_usage_chart');

    // Fetch water data for the specified period
    $data = fetch_water_data($atts['period']);

    // Generate a unique ID for each chart
    $chart_id = uniqid('waterUsageChart_');

    // Prepare data for Chart.js
    switch ($atts['period']) {
        case 'hourly':
            $labels = range(0, 23); // Hours of the day (0-23)
            $label_string = 'Time (Hours)';
            $title = 'Daily Water Usage';
            $usage_text = 'today';
            break;
        case 'daily':
            $labels = range(1, count($data)); // Days of the month (1-31)
            $label_string = 'Time (Days)';
            $title = 'Monthly Water Usage';
            $usage_text = 'this month';
            break;
        case 'monthly':
            $labels = array('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec');
            $label_string = 'Time (Months)';
            $title = 'Yearly Water Usage';
            $usage_text = 'this year';
            break;
        case 'yearly':
            $labels = array(date('Y')); // Current year
            $label_string = 'Time (Year)';
            $title = 'Yearly Water Usage';
            $usage_text = 'this year';
            break;
        default:
            return ''; // Invalid period
    }

    // Prepare output HTML
    $output = "<p class='usagecount'>You have used <span id='total_water_usage_{$chart_id}'>" . round((array_sum($data) / 1000), 2) . "</span> L of water $usage_text.</p>";
    $output .= "<canvas id='{$chart_id}' width='400' height='200'></canvas>";
    $output .= "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
    $output .= "<script>";
    $output .= "document.addEventListener('DOMContentLoaded', function() {";
    $output .= "    var ctx = document.getElementById('{$chart_id}').getContext('2d');";
    $output .= "    var myChart = new Chart(ctx, {";
    $output .= "        type: 'bar',"; // Change chart type to bar for daily data
    $output .= "        data: {";
    $output .= "            labels: " . json_encode($labels) . ",";
    $output .= "            datasets: [{";
    $output .= "                label: 'Water Usage (mL)',";
    $output .= "                data: " . json_encode($data) . ",";
    $output .= "                backgroundColor: 'rgba(54, 162, 235, 0.5)',";
    $output .= "                borderColor: 'rgba(54, 162, 235, 1)',";
    $output .= "                borderWidth: 1";
    $output .= "            }]";
    $output .= "        },";
    $output .= "        options: {";
    $output .= "            title: {";
    $output .= "                display: true,";
    $output .= "                text: '$title'";
    $output .= "            },";
    $output .= "            scales: {";
    $output .= "                yAxes: [{";
    $output .= "                    scaleLabel: {";
    $output .= "                        display: true,";
    $output .= "                        labelString: 'Water Usage (mL)',"; // Label for Y-axis
    $output .= "                    }";
    $output .= "                }],";
    $output .= "                xAxes: [{";
    $output .= "                    scaleLabel: {";
    $output .= "                        display: true,";
    $output .= "                        labelString: '$label_string',"; // Label for X-axis
    $output .= "                    }";
    $output .= "                }],";
    $output .= "            }";
    $output .= "        }";
    $output .= "    });";

    // AJAX request to update chart data
    $output .= "    function updateChart_{$chart_id}() {";
    $output .= "        var xhr = new XMLHttpRequest();";
    $output .= "        xhr.onreadystatechange = function() {";
    $output .= "            if (xhr.readyState === XMLHttpRequest.DONE) {";
    $output .= "                if (xhr.status === 200) {";
    $output .= "                    var responseData = JSON.parse(xhr.responseText);";
    $output .= "                    var newData = responseData.data;";
    $output .= "                    var totalUsage = responseData.total_usage;";
    $output .= "                    myChart.data.datasets[0].data = newData;";
    $output .= "                    myChart.update();";
    $output .= "                    document.getElementById('total_water_usage_{$chart_id}').innerText = totalUsage;";
    $output .= "                } else {";
    $output .= "                    console.error('Error: ' + xhr.status);";
    $output .= "                }";
    $output .= "            }";
    $output .= "        };";
    $output .= "        xhr.open('GET', '" . admin_url('admin-ajax.php') . "?action=update_water_usage_chart&period={$atts['period']}&chart_id={$chart_id}', true);";
    $output .= "        xhr.send();";
    $output .= "    }";

    // Call updateChart function every 60 seconds
    $output .= "    updateChart_{$chart_id}();"; // Initial call to update chart data
    $output .= "    setInterval(updateChart_{$chart_id}, 5000);";
    $output .= "});";
    $output .= "</script>";

    return $output;
}

function register_water_usage_chart_shortcode() {
    add_shortcode('water_usage_chart', 'output_water_usage_chart_shortcode');
}

add_action('init', 'register_water_usage_chart_shortcode');

function update_water_usage_chart() {
    $period = isset($_GET['period']) ? $_GET['period'] : 'daily';
    $chart_id = isset($_GET['chart_id']) ? sanitize_text_field($_GET['chart_id']) : '';
    $data = fetch_water_data($period);

    wp_send_json(array("data" => $data, "total_usage" => round(array_sum($data) / 1000, 2), "chart_id" => $chart_id));
}

add_action('wp_ajax_update_water_usage_chart', 'update_water_usage_chart');
add_action('wp_ajax_nopriv_update_water_usage_chart', 'update_water_usage_chart');

# Onboarding
function combined_onboarding_form_shortcode() {
    if (!is_user_logged_in()) {
        return 'You need to be logged in to complete onboarding.';
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;
    $onboarding_complete = get_user_meta($user_id, 'onboarding_complete', true);

    if ($onboarding_complete) {
        return 'You have already completed onboarding.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $usage_type = sanitize_text_field($_POST['water_usage_type']);
        $household_size = sanitize_text_field($_POST['household_size']);
        $water_conservation = sanitize_text_field($_POST['water_conservation']);
        $tank_capacity = $usage_type === 'limit' ? intval($_POST['tank_capacity']) : null;
        $appliance_number = sanitize_text_field($_POST['appliance_number']);
        $appliance_type = sanitize_text_field($_POST['appliance_type']);
        $nicename = sanitize_text_field($_POST['nicename']);

        // Save water usage type
        update_user_meta($user_id, 'water_usage_type', $usage_type);

        // Save user preferences
        update_user_meta($user_id, 'household_size', $household_size);
        update_user_meta($user_id, 'water_conservation', $water_conservation);

        // Save tank capacity if applicable
        if ($usage_type === 'limit') {
            update_user_meta($user_id, 'tank_capacity', $tank_capacity);
        }

        // Save device assignment
        global $wpdb;
        $existing_entry = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM wp_devices WHERE appliance_number = %s AND user_id = %d",
            $appliance_number, $user_id
        ));
        if ($existing_entry == 0) {
            $wpdb->insert('wp_devices', array(
                'appliance_number' => $appliance_number,
                'appliance_type' => $appliance_type,
                'nicename' => $nicename,
                'user_id' => $user_id
            ));
        }

        // Determine the water limit
        $limit_value = $usage_type === 'limit' ? $tank_capacity * 1000 : calculate_water_budget($household_size, $water_conservation) * 1000;

        // Insert or update the wp_water_limits table with the user's limit
        $wpdb->insert('wp_water_limits', array(
            'user_id' => $user_id,
            'updatetime' => current_time('mysql'),
            'limit' => $limit_value
        ));

        // Mark onboarding as complete
        update_user_meta($user_id, 'onboarding_complete', true);

        // Redirect to a success page
        wp_redirect(add_query_arg('budget', $limit_value / 1000, home_url('/dashboard')));
        exit;
    }

    ob_start();
    ?>
    <form method="post" id="onboarding-form">
        <h2>Water Usage Type</h2>
        <p>Do you have a water limit or are you on the grid?</p>
        <label>
            <input type="radio" name="water_usage_type" value="limit" required> I have a water limit
        </label><br>
        <label>
            <input type="radio" name="water_usage_type" value="grid" required> I'm on the grid
        </label><br>

        <div id="tank-capacity-section" style="display: none;">
            <h2>Tank Information</h2>
            <label for="tank_capacity">How much water is currently in your tank (L)?</label>
            <input type="number" id="tank_capacity" name="tank_capacity" min="0"><br>
        </div>

        <div id="user-preferences-section" style="display: none;">
            <h2>User Preferences</h2>
            <label for="household_size">How many people are in your household?</label>
            <select id="household_size" name="household_size" required>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3-5">3-5</option>
                <option value="6+">6+</option>
            </select><br>

            <label for="water_conservation">How conservative with your water would you like to be?</label>
            <select id="water_conservation" name="water_conservation" required>
                <option value="very">Very Conservative</option>
                <option value="moderate">Moderate</option>
                <option value="liberal">Liberal</option>
            </select><br>
        </div>

        <div id="water-budget" style="display: none;">
            <h3>Your Monthly Water Budget</h3>
            <p id="water-budget-value"></p>
        </div>

        <h2>Device Assignment</h2>
        <label for="appliance_number">Appliance Number:</label>
        <input type="text" id="appliance_number" name="appliance_number" required><br>

        <label for="appliance_type">Appliance Type:</label>
        <select id="appliance_type" name="appliance_type" required>
            <option value="washing_machine">Washing Machine</option>
            <option value="sink">Sink</option>
            <option value="shower">Shower</option>
            <option value="outdoor_tap">Outdoor Tap</option>
            <option value="bath">Bath</option>
        </select><br>

        <label for="nicename">Nickname:</label>
        <input type="text" id="nicename" name="nicename" required><br>

        <input type="submit" value="Submit">
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var waterUsageTypeInputs = document.getElementsByName('water_usage_type');
            var householdSizeSelect = document.getElementById('household_size');
            var waterConservationSelect = document.getElementById('water_conservation');
            var tankCapacitySection = document.getElementById('tank-capacity-section');
            var userPreferencesSection = document.getElementById('user-preferences-section');
            var waterBudgetDiv = document.getElementById('water-budget');
            var waterBudgetValue = document.getElementById('water-budget-value');

            function updateFormSections() {
                var selectedValue = document.querySelector('input[name="water_usage_type"]:checked').value;
                if (selectedValue === 'limit') {
                    tankCapacitySection.style.display = 'block';
                    userPreferencesSection.style.display = 'none';
                    waterBudgetDiv.style.display = 'none';
                } else {
                    tankCapacitySection.style.display = 'none';
                    userPreferencesSection.style.display = 'block';
                    updateWaterBudget();
                }
            }

            function updateWaterBudget() {
                var householdSize = householdSizeSelect.value;
                var waterConservation = waterConservationSelect.value;
                if (householdSize && waterConservation) {
                    var monthlyBudget = calculateWaterBudget(householdSize, waterConservation);
                    waterBudgetValue.textContent = 'Based on your preferences, your estimated monthly water budget is ' + monthlyBudget.toLocaleString() + ' liters.';
                    waterBudgetDiv.style.display = 'block';
                } else {
                    waterBudgetDiv.style.display = 'none';
                }
            }

            function calculateWaterBudget(householdSize, waterConservation) {
                var baseUsage = 3000; // Base usage per person per month in liters
                var people;
                var conservationFactor;

                switch (householdSize) {
                    case '1':
                        people = 1;
                        break;
                    case '2':
                        people = 2;
                        break;
                    case '3-5':
                        people = 4; // Average 4 people for 3-5 category
                        break;
                    case '6+':
                        people = 6; // Minimum 6 people for 6+ category
                        break;
                }

                switch (waterConservation) {
                    case 'very':
                        conservationFactor = 0.75; // 25% reduction
                        break;
                    case 'moderate':
                        conservationFactor = 1.0; // No change
                        break;
                    case 'liberal':
                        conservationFactor = 1.25; // 25% increase
                        break;
                }

                return Math.round(baseUsage * people * conservationFactor);
            }

            waterUsageTypeInputs.forEach(function(input) {
                input.addEventListener('change', updateFormSections);
            });

            householdSizeSelect.addEventListener('change', updateWaterBudget);
            waterConservationSelect.addEventListener('change', updateWaterBudget);

            updateFormSections(); // Initial check
        });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('combined_onboarding_form', 'combined_onboarding_form_shortcode');

function calculate_water_budget($household_size, $water_conservation) {
    $base_usage = 3000; // Base usage per person per month in liters

    switch ($household_size) {
        case '1':
            $people = 1;
            break;
        case '2':
            $people = 2;
            break;
        case '3-5':
            $people = 4; // Average 4 people for 3-5 category
            break;
        case '6+':
            $people = 6; // Minimum 6 people for 6+ category
            break;
    }

    switch ($water_conservation) {
        case 'very':
            $conservation_factor = 0.75; // 25% reduction
            break;
        case 'moderate':
            $conservation_factor = 1.0; // No change
            break;
        case 'liberal':
            $conservation_factor = 1.25; // 25% increase
            break;
    }

    $monthly_budget = $base_usage * $people * $conservation_factor;
    return $monthly_budget;
}

function redirect_to_onboarding() {
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $onboarding_complete = get_user_meta($user_id, 'onboarding_complete', true);

        // Check if the current page is not the onboarding page and the user is not an admin to avoid redirect loop
        if (!$onboarding_complete && !is_page('onboarding') && !current_user_can('administrator')) {
            wp_redirect(home_url('/onboarding'));
            exit;
        }
    }
}
add_action('template_redirect', 'redirect_to_onboarding');

# Rewards Data
// Function to fetch the user's water limit
function fetch_user_limit($user_id) {
    global $wpdb;
    $limit = $wpdb->get_var($wpdb->prepare(
        "SELECT `limit` FROM `wp_water_limits` WHERE `user_id` = %d ORDER BY `updatetime` DESC LIMIT 1",
        $user_id
    ));
    return $limit;
}

// Shortcode function to output the user's water limit
function user_limit_shortcode($atts) {
    // Get the current user's ID
    $user_id = get_current_user_id();
    
    // Fetch the user's water limit
    $limit = fetch_user_limit($user_id);

    $litrelimit = $limit / 1000;
    
    // If no limit is found, return a default message
    if ($limit === null) {
        return 'No water limit found for this user.';
    }
    
    // Return the user's water limit
    return $litrelimit . ' L';
}

// Register the shortcode
add_shortcode('user_limit', 'user_limit_shortcode');

// Function to fetch recent water usage data for the user
function fetch_recent_water_usage_data($user_id, $start_date, $end_date) {
    global $wpdb;

    // Prepare the data array
    $usage_data = [];

    // Calculate the months between start_date and end_date
    $current_date = $start_date;
    while (strtotime($current_date) <= strtotime($end_date)) {
        $usage_data[date('Y-m', strtotime($current_date))] = 0;
        $current_date = date('Y-m-d', strtotime($current_date . ' +1 month'));
    }

    // Query to retrieve water usage data for the specified period
    $query = $wpdb->prepare("
        SELECT SUM(ad.value) AS total_usage, DATE_FORMAT(ad.received_at, '%%Y-%%m') AS month
        FROM wp_arduino_data ad
        INNER JOIN wp_devices d ON ad.appliance_no = d.appliance_number
        WHERE ad.received_at BETWEEN %s AND %s AND d.user_id = %d
        GROUP BY month
    ", $start_date, $end_date, $user_id);

    $results = $wpdb->get_results($query);

    // Fill in the usage data array with values from the database
    foreach ($results as $result) {
        $usage_data[$result->month] = $result->total_usage;
    }

    return $usage_data;
}




// Shortcode to display previous 3 months rewards
function previous_rewards_shortcode() {
    $user_data = fetch_user_rewards_data();
    if ($user_data) {
        $start_date = date('Y-m-01', strtotime('-3 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        $usage_data = fetch_recent_water_usage_data($user_data->user_id, $start_date, $end_date);
        return generate_previous_rewards_output($user_data, $usage_data);
    } else {
        return '<p>You need to be logged in to view your rewards.</p>';
    }
}
add_shortcode('previous_rewards', 'previous_rewards_shortcode');

// Function to generate the previous rewards output based on the user's water usage data
function generate_previous_rewards_output($user_data, $usage_data) {
    $output = '<div class="rewards-container">'; // Add parent div here

    foreach ($usage_data as $month => $usage) {
        $budget_liters = $user_data->limit / 1000;
        $usage_liters = $usage / 1000;
        $month_name = date('F Y', strtotime($month));

        // Past months
        if ($usage_liters <= $budget_liters) {
            $output .= "<div class='rewards rewards-green'><h3>{$month_name}</h3>
            <p>You used {$usage_liters}L out of {$budget_liters}L. Good job!</p></div>";
        } else {
            $over_budget = $usage_liters - $budget_liters;
            $output .= "<div class='rewards rewards-red'><h3>{$month_name}</h3>
            <p>You used {$usage_liters}L out of {$budget_liters}L. Sorry, you went over your water budget by {$over_budget}L.</p></div>";
        }
    }

    $output .= '</div>'; // Close parent div here
    return $output;
}


// Shortcode to display upcoming 3 months rewards
function upcoming_rewards_shortcode() {
    $user_data = fetch_user_rewards_data();
    if ($user_data) {
        $start_date = date('Y-m-01', strtotime('+1 month'));
        $end_date = date('Y-m-t', strtotime('+3 month'));
        $usage_data = fetch_recent_water_usage_data($user_data->user_id, $start_date, $end_date);
        return generate_upcoming_rewards_output($user_data, $usage_data);
    } else {
        return '<p>You need to be logged in to view your rewards.</p>';
    }
}
add_shortcode('upcoming_rewards', 'upcoming_rewards_shortcode');

// Function to generate the upcoming rewards output based on the user's water usage data
function generate_upcoming_rewards_output($user_data, $usage_data) {
    $output = '<div class="rewards-container">'; // Add parent div here

    foreach ($usage_data as $month => $usage) {
        $budget_liters = $user_data->limit / 1000;
        $month_name = date('F Y', strtotime($month));

        // Future months
        $output .= "<div class='rewards rewards-grey'><h3>{$month_name}</h3>
        <p>Upcoming month. Stay within your limit of {$budget_liters}L to earn rewards!</p></div>";
    }

    $output .= '</div>'; // Close parent div here
    return $output;
}

// Shortcode to display current month progress
// Shortcode to display current month progress
function current_month_rewards_shortcode() {
    $user_data = fetch_user_rewards_data();
    if ($user_data) {
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $usage_data = fetch_recent_water_usage_data($user_data->user_id, $start_date, $end_date);
        return generate_current_month_rewards_output($user_data, $usage_data);
    } else {
        return '<p>You need to be logged in to view your rewards.</p>';
    }
}
add_shortcode('current_month_rewards', 'current_month_rewards_shortcode');

// Function to generate the current month rewards output based on the user's water usage data
function generate_current_month_rewards_output($user_data, $usage_data) {
    $output = '<div class="rewards-container">'; // Add parent div here

    foreach ($usage_data as $month => $usage) {
        $budget_liters = $user_data->limit / 1000;
        $usage_liters = $usage / 1000;
        $nice_liters = round(($budget_liters - $usage_liters), 2);
        $month_name = date('F Y', strtotime($month));

        // Current month
        if ($month === date('Y-m')) {
            if ($usage_liters <= $budget_liters) {
                $output .= "

                <div class='rewards rewards-current'><script src='https://cdn.lordicon.com/lordicon.js'></script>
                <h2 style='color:white; text-align:center; font-size:50px;'> This Month's Reward </h2><div> <div style='width:100%; display:flex; justify-content:center;'><lord-icon
                src='https://cdn.lordicon.com/rqdzxkkr.json'
                trigger='hover'
                stroke='bold'
                colors='primary:#ffffff,secondary:#ffffff'
                style='width:150px;height:150px;padding-top:15px;'>
            </lord-icon></div><br/> <h3 style='font-size:50px; text-align:center;'>You're on track!</h3>
                <h2 style='font-size:75px; text-align:center; color:white;'> {$nice_liters}L Left </h2>
                <p style='text-align:center;'>Good job, you have only used {$usage_liters}L out of {$budget_liters}L! <br/> Stay on track to receive a $30 water rebate at the end of the month.</p></div>
                <h3 style='text-align:center;'>{$month_name}</h3>
                </div>";
            } else {
                $over_budget = $usage_liters - $budget_liters;
                $nice_budget = round($over_budget, 2);
                $output .= "

                <div class='rewards rewards-current'><script src='https://cdn.lordicon.com/lordicon.js'></script>
                <h2 style='color:white; text-align:center; font-size:50px;'> This Month's Reward </h2><div> <div style='width:100%; display:flex; justify-content:center;'><lord-icon
                src='https://cdn.lordicon.com/xzybfbcm.json'
                trigger='hover'
                stroke='bold'
                colors='primary:#ffffff,secondary:#ffffff'
                style='width:150px;height:150px;padding-top:15px;'>
            </lord-icon></div><br/> <h3 style='font-size:50px; text-align:center;'>You've gone over budget!</h3>
                <h2 style='font-size:75px; text-align:center; color:white;'> {$nice_budget}L Over </h2>
                <p style='text-align:center;'>You're over budget this month! You wont be elligble for a reward.  <br> Try again next month to receive a water rebate!</p></div>
                <h3 style='text-align:center;'>{$month_name}</h3>
                </div>";

                $output .= "<div class='rewards rewards-current'><h3>{$month_name}</h3>";

            }
        }
    }

    $output .= '</div>'; // Close parent div here
    return $output;
}

function fetch_user_rewards_data() {
    $user_id = get_current_user_id();

    // Fetch the latest water limit for the user
    $limit = fetch_user_limit($user_id);

    // Fetch the user's water usage type from user metadata
    $usage_type = get_user_meta($user_id, 'water_usage_type', true);

    // Return user data with the latest limit and usage type
    return (object) [
        'user_id' => $user_id,
        'usage_type' => $usage_type,
        'limit' => $limit,
    ];
}



# User Settings
function user_settings_form_shortcode() {
    if (!is_user_logged_in()) {
        return 'You need to be logged in to update your settings.';
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $usage_type = sanitize_text_field($_POST['water_usage_type']);
        $household_size = sanitize_text_field($_POST['household_size']);
        $water_conservation = sanitize_text_field($_POST['water_conservation']);
        $tank_capacity = $usage_type === 'limit' ? intval($_POST['tank_capacity']) : null;

        // Save water usage type
        update_user_meta($user_id, 'water_usage_type', $usage_type);

        // Save user preferences
        update_user_meta($user_id, 'household_size', $household_size);
        update_user_meta($user_id, 'water_conservation', $water_conservation);

        // Save tank capacity if applicable
        if ($usage_type === 'limit') {
            update_user_meta($user_id, 'tank_capacity', $tank_capacity);
        }

        // Determine the water limit
        $limit_value = $usage_type === 'limit' ? $tank_capacity * 1000 : calculate_water_budget($household_size, $water_conservation) * 1000;

        // Insert or update the wp_water_limits table with the user's limit
        global $wpdb;
        $wpdb->replace('wp_water_limits', array(
            'user_id' => $user_id,
            'updatetime' => current_time('mysql'),
            'limit' => $limit_value
        ));

        // Redirect to a success page
        wp_redirect(add_query_arg('settings_updated', 'true', home_url('/settings')));
        exit;
    }

    ob_start();
    ?>
    
    <form method="post" id="user-settings-form">
        <div id="usagetype">
            <h2>Water Usage Type</h2>
            <p>Do you have a water limit or are you on the grid?</p>
            <label>
                <input type="radio" name="water_usage_type" value="limit" required <?php checked(get_user_meta($user_id, 'water_usage_type', true), 'limit'); ?>> I have a water limit
            </label><br>
            <label>
                <input type="radio" name="water_usage_type" value="grid" required <?php checked(get_user_meta($user_id, 'water_usage_type', true), 'grid'); ?>> I'm on the grid
            </label><br>

            <div id="tank-capacity-section" style="display: none;">
                <h2>Tank Information</h2>
                <label for="tank_capacity">How much water is currently in your tank (L)?</label>
                <input type="number" id="tank_capacity" name="tank_capacity" min="0" value="<?php echo esc_attr(get_user_meta($user_id, 'tank_capacity', true)); ?>"><br>
            </div>
        </div>
        <div id="user-preferences-section" style="display: none;">
            <h2>User Preferences</h2>
            <label for="household_size">How many people are in your household?</label>
            <select id="household_size" name="household_size" required>
                <option value="1" <?php selected(get_user_meta($user_id, 'household_size', true), '1'); ?>>1</option>
                <option value="2" <?php selected(get_user_meta($user_id, 'household_size', true), '2'); ?>>2</option>
                <option value="3-5" <?php selected(get_user_meta($user_id, 'household_size', true), '3-5'); ?>>3-5</option>
                <option value="6+" <?php selected(get_user_meta($user_id, 'household_size', true), '6+'); ?>>6+</option>
            </select><br>

            <label for="water_conservation">How conservative with your water would you like to be?</label>
            <select id="water_conservation" name="water_conservation" required>
                <option value="very" <?php selected(get_user_meta($user_id, 'water_conservation', true), 'very'); ?>>Very Conservative</option>
                <option value="moderate" <?php selected(get_user_meta($user_id, 'water_conservation', true), 'moderate'); ?>>Moderate</option>
                <option value="liberal" <?php selected(get_user_meta($user_id, 'water_conservation', true), 'liberal'); ?>>Liberal</option>
            </select><br>
        </div>

        <div id="water-budget" style="display: none;">
            <h2>Your Monthly Water Budget</h2>
            <p id="water-budget-value"></p>
        </div>

        <input type="submit" value="Update Settings">
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var waterUsageTypeInputs = document.getElementsByName('water_usage_type');
            var householdSizeSelect = document.getElementById('household_size');
            var waterConservationSelect = document.getElementById('water_conservation');
            var tankCapacitySection = document.getElementById('tank-capacity-section');
            var userPreferencesSection = document.getElementById('user-preferences-section');
            var waterBudgetDiv = document.getElementById('water-budget');
            var waterBudgetValue = document.getElementById('water-budget-value');

            function updateFormSections() {
                var selectedValue = document.querySelector('input[name="water_usage_type"]:checked').value;
                if (selectedValue === 'limit') {
                    tankCapacitySection.style.display = 'block';
                    userPreferencesSection.style.display = 'none';
                    waterBudgetDiv.style.display = 'none';
                } else {
                    tankCapacitySection.style.display = 'none';
                    userPreferencesSection.style.display = 'block';
                    updateWaterBudget();
                }
            }

            function updateWaterBudget() {
                var householdSize = householdSizeSelect.value;
                var waterConservation = waterConservationSelect.value;
                if (householdSize && waterConservation) {
                    var monthlyBudget = calculateWaterBudget(householdSize, waterConservation);
                    waterBudgetValue.textContent = 'Based on your preferences, your estimated monthly water budget is ' + monthlyBudget.toLocaleString() + ' liters.';
                    waterBudgetDiv.style.display = 'block';
                } else {
                    waterBudgetDiv.style.display = 'none';
                }
            }

            function calculateWaterBudget(householdSize, waterConservation) {
                var baseUsage = 3000; // Base usage per person per month in liters
                var people;
                var conservationFactor;

                switch (householdSize) {
                    case '1':
                        people = 1;
                        break;
                    case '2':
                        people = 2;
                        break;
                    case '3-5':
                        people = 4; // Average 4 people for 3-5 category
                        break;
                    case '6+':
                        people = 6; // Minimum 6 people for 6+ category
                        break;
                }

                switch (waterConservation) {
                    case 'very':
                        conservationFactor = 0.75; // 25% reduction
                        break;
                    case 'moderate':
                        conservationFactor = 1.0; // No change
                        break;
                    case 'liberal':
                        conservationFactor = 1.25; // 25% increase
                        break;
                }

                return Math.round(baseUsage * people * conservationFactor);
            }

            waterUsageTypeInputs.forEach(function(input) {
                input.addEventListener('change', updateFormSections);
            });

            householdSizeSelect.addEventListener('change', updateWaterBudget);
            waterConservationSelect.addEventListener('change', updateWaterBudget);

            updateFormSections(); // Initial check
        });
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('user_settings_form', 'user_settings_form_shortcode');

# Water Limit Stuff
// Function to output the water limit update form for the current user
function water_limit_update_form_shortcode() {
    if (!is_user_logged_in()) {
        return 'You need to be logged in to update the water limit.';
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    if (isset($_POST['update_limit'])) {
        global $wpdb;
        $new_limit = intval($_POST['new_limit']);

        $wpdb->insert(
            'wp_water_limits',
            array(
                'limit' => $new_limit,
                'user_id' => $user_id,
                'updatetime' => current_time('mysql')
            ),
            array('%d', '%d', '%s')
        );
    }

    $form_html = '
    <form class="test" method="post" >
        <label for="new_limit"></label>
        <input type="number" id="new_limit" name="new_limit" required>
        <input type="submit" name="update_limit" value="Update Limit" style="margin-top:15px;">
    </form>
    ';

    return $form_html;
}

add_shortcode('water_limit_update_form', 'water_limit_update_form_shortcode');

function fetch_water_limit_data() {
    if (!is_user_logged_in()) {
        return null;
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    global $wpdb;

    $limit_query = $wpdb->get_row($wpdb->prepare(
        "SELECT updatetime, `limit`, user_id FROM wp_water_limits WHERE user_id = %d ORDER BY updatetime DESC LIMIT 1",
        $user_id
    ));

    $usage_type = get_user_meta($user_id, 'water_usage_type', true);

    return (object) array_merge((array) $limit_query, ['usage_type' => $usage_type]);
}


// Function to calculate updated water limit based on usage type
function calculate_updated_water_limit($limit_query) {
    if ($limit_query) {
        $limit_time = $limit_query->updatetime;
        $original_limit = $limit_query->limit;
        $usage_type = $limit_query->usage_type;
        $user_id = $limit_query->user_id;

        global $wpdb;

        if ($usage_type == 'grid') {
            $current_date = current_time('mysql'); // Correct format for MySQL datetime
            $first_day_of_month = date('Y-m-01 00:00:00'); // Correct format for MySQL datetime

            $query = $wpdb->prepare(
                "SELECT SUM(ad.value) AS sum_values
                 FROM wp_arduino_data ad
                 INNER JOIN wp_devices d ON ad.appliance_no = d.appliance_number
                 WHERE ad.received_at >= %s AND ad.received_at <= %s AND d.user_id = %d",
                $first_day_of_month, $current_date, $user_id
            );

            $sum_result = $wpdb->get_row($query);
        } else {
            $sum_result = $wpdb->get_row($wpdb->prepare(
                "SELECT SUM(ad.value) AS sum_values 
                 FROM wp_arduino_data ad
                 INNER JOIN wp_devices d ON ad.appliance_no = d.appliance_number
                 WHERE ad.received_at > %s AND d.user_id = %d",
                $limit_time, $user_id
            ));
        }

        $sum_values = $sum_result ? $sum_result->sum_values : 0;
        $updated_limit = $original_limit - $sum_values;
        return $updated_limit;
    } else {
        return null;
    }
}



function generate_updated_water_limit_output($updated_limit, $usage_type) {
    if ($updated_limit !== null) {
        $formatted_limit = number_format($updated_limit / 1000, 2);

        if ($usage_type == 'grid') {
            $message = $updated_limit >= 0 
                ? "You have {$formatted_limit}L left in your budget this month."
                : "You are " . abs($formatted_limit) . "L over budget this month.";
        } else {
            $message = "You have {$formatted_limit}L left in your tank.";
        }

                // Prepare output
        $output = '<h2 class="leftintank">' . $formatted_limit . 'L</h2>';
        $output .= '<p class="tankmsg">' . $message . '</p>';
        $output .= '<script>

            document.addEventListener("DOMContentLoaded", function() {
                var usageType = "' . $usage_type . '";
                var rewardsButton = document.querySelector(".rewardsbutton");
                var topupButton = document.querySelector(".topup");

                if (usageType === "grid") {
                    if (rewardsButton) rewardsButton.style.display = "block";
                    if (topupButton) topupButton.style.display = "none";
                } else {
                    if (rewardsButton) rewardsButton.style.display = "none";
                    if (topupButton) topupButton.style.display = "block";
                }
            });
        </script>';
        return $output;
    } else {
        return '<p>0</p>';
    }
}



// Function to update water limit via AJAX
function update_water_limit() {
    $limit_query = fetch_water_limit_data();
    $updated_limit = calculate_updated_water_limit($limit_query);
    $output = generate_updated_water_limit_output($updated_limit, $limit_query->usage_type);
    wp_send_json(array("output" => $output));
}

add_action('wp_ajax_update_water_limit', 'update_water_limit');
add_action('wp_ajax_nopriv_update_water_limit', 'update_water_limit');


// Function to output water limit shortcode
function update_water_limit_shortcode() {
    $limit_query = fetch_water_limit_data();
    $updated_limit = calculate_updated_water_limit($limit_query);
    $output = generate_updated_water_limit_output($updated_limit, $limit_query->usage_type);

    $output .= "<script>
        function updateWaterLimit() {
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    if (xhr.status === 200) {
                        var responseData = JSON.parse(xhr.responseText);
                        document.getElementById('water_limit_output').innerHTML = responseData.output;
                    } else {
                        console.error('Error: ' + xhr.status);
                    }
                }
            };
            xhr.open('GET', '" . admin_url('admin-ajax.php') . "?action=update_water_limit', true);
            xhr.send();
        }
        setInterval(updateWaterLimit, 10000);
        updateWaterLimit();
    </script>";

    return '<div id="water_limit_output">' . $output . '</div>';
}

add_shortcode('update_water_limit', 'update_water_limit_shortcode');


