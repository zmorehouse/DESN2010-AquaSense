<?php
# Child Theme 
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_style' );
				function hello_elementor_child_style() {
					wp_enqueue_style( 'parent-style', get_template_directory_uri() . '/style.css' );
					wp_enqueue_style( 'child-style', get_stylesheet_directory_uri() . '/style.css', array('parent-style') );
				}

# Redirection and login rules
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


# Arduino Stuff

add_action('wp_ajax_receive_data_from_arduino', 'receive_data_from_arduino');
add_action('wp_ajax_nopriv_receive_data_from_arduino', 'receive_data_from_arduino');

function receive_data_from_arduino() {
    error_log("receive_data_from_arduino() function called");

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


# Water Shortcodes

# Water Usage by Appliance// Function to fetch water usage data from the database
function fetch_water_usage_data() {
    // Connect to the database
    global $wpdb;

    // Get the current date in the WordPress timezone
    $current_date_wp_timezone = current_time('Y-m-d');

    // Get the WordPress timezone offset in seconds
    $timezone_offset_seconds = get_option('gmt_offset') * 3600;

    // Calculate the start of the current day in the database timezone
    $start_of_day_db_timezone = date('Y-m-d 00:00:00', strtotime($current_date_wp_timezone) + $timezone_offset_seconds);

    // Query to retrieve water usage data for the last day
    $query = $wpdb->prepare("SELECT SUM(value) AS total_usage, appliance_no 
                             FROM wp_arduino_data 
                             WHERE received_at >= %s 
                             GROUP BY appliance_no", $start_of_day_db_timezone);
    return $wpdb->get_results($query);
}

// Function to calculate water usage percentages
function calculate_water_usage_percentages() {
    // Fetch water usage data
    $usage_data = fetch_water_usage_data();

    // Initialize variables to store total usage and individual appliance usage
    $total_usage = 0;
    $appliance_usage = array();

    // Iterate through the results to calculate total usage and individual appliance usage
    foreach ($usage_data as $data_point) {
        $total_usage += $data_point->total_usage;
        $appliance_usage[$data_point->appliance_no] = $data_point->total_usage;
    }

    // Calculate percentages
    $percentages = array();
    foreach ($appliance_usage as $appliance_no => $usage) {
        $percentage = ($usage / $total_usage) * 100;
        $percentages[$appliance_no] = $percentage;
    }

    return $percentages;
}

// Function to generate the output HTML for water usage percentages
function generate_water_usage_percentages_output($percentages) {
    // Prepare output
    $output = '<h3>Water Usage Percentages by Appliance:</h3>';
    foreach ($percentages as $appliance_no => $percentage) {
        if ($appliance_no == 1) {
            $appliance_name = "Taps";
        } elseif ($appliance_no == 2) {
            $appliance_name = "Washing";
        } elseif ($appliance_no == 3) {
            $appliance_name = "Showers";
        } else {
            $appliance_name = "Appliance " . $appliance_no;
        }
        $output .= "$appliance_name: <span class='percentage' data-appliance='$appliance_no'>" . number_format($percentage, 2) . "%</span><br>";
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
    $output .= "                    var applianceNo = element.getAttribute('data-appliance');";
    $output .= "                    if (percentages.hasOwnProperty(applianceNo)) {";
    $output .= "                        element.textContent = percentages[applianceNo].toFixed(2) + '%';";
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


# Water Limiter 

// Function to fetch water limit data from the database
function fetch_water_limit_data() {
    // Connect to the database
    global $wpdb;

    // Query to get the time the limit was set for user 'zac'
    $limit_query = $wpdb->get_row("SELECT updatetime, `limit` FROM wp_water_limits WHERE user = 'zac' ORDER BY updatetime DESC LIMIT 1");

    return $limit_query;
}

// Function to calculate and update water limit
function calculate_updated_water_limit($limit_query) {
    if ($limit_query) {
        $limit_time = $limit_query->updatetime;
        $original_limit = $limit_query->limit;

        // Connect to the database
        global $wpdb;

        // Query to get the sum of values past the limit time from wp_arduino_data
        $sum_query = $wpdb->prepare("SELECT SUM(value) AS sum_values 
                                     FROM wp_arduino_data 
                                     WHERE received_at > %s", $limit_time);
        $sum_result = $wpdb->get_row($sum_query);

        // Calculate the updated limit
        $updated_limit = $original_limit - $sum_result->sum_values;

        return $updated_limit;
    } else {
        return null;
    }
}

// Function to generate the output HTML for updated water limit
function generate_updated_water_limit_output($updated_limit) {
    if ($updated_limit !== null) {
        // Prepare output
        $output .= '<p class="leftintank">' . round($updated_limit / 1000, 2) . 'L </p>';
        return $output;
    } else {
        return '<p>0</p>';
    }
}

// Function to update water limit via AJAX
// Function to update water limit via AJAX
function update_water_limit() {
    // Fetch water limit data
    $limit_query = fetch_water_limit_data();

    // Calculate updated water limit
    $updated_limit = calculate_updated_water_limit($limit_query);

    // Update water limit in the database if it's not null
    if ($updated_limit !== null) {
        global $wpdb;
        $wpdb->update(
            'wp_water_limits',
            array('limit' => $updated_limit),
            array('user' => 'zac'),
            array('%d'),
            array('%s')
        );
    }

    // Generate output HTML for updated water limit
    $output = generate_updated_water_limit_output($updated_limit);

    // Return JSON response with updated water limit HTML
    wp_send_json(array("output" => $output));
}


// Register AJAX action
add_action('wp_ajax_update_water_limit', 'update_water_limit');
add_action('wp_ajax_nopriv_update_water_limit', 'update_water_limit');

// Function to output water limit shortcode
function update_water_limit_shortcode() {
    // Fetch water limit data
    $limit_query = fetch_water_limit_data();

    // Calculate updated water limit
    $updated_limit = calculate_updated_water_limit($limit_query);

    // Generate output HTML for updated water limit
    $output = generate_updated_water_limit_output($updated_limit);

    // Add JavaScript for real-time updating
    $output .= "<script>";
    $output .= "function updateWaterLimit() {";
    $output .= "    var xhr = new XMLHttpRequest();";
    $output .= "    xhr.onreadystatechange = function() {";
    $output .= "        if (xhr.readyState === XMLHttpRequest.DONE) {";
    $output .= "            if (xhr.status === 200) {";
    $output .= "                var responseData = JSON.parse(xhr.responseText);";
    $output .= "                document.getElementById('water_limit_output').innerHTML = responseData.output;";
    $output .= "            } else {";
    $output .= "                console.error('Error: ' + xhr.status);";
    $output .= "            }";
    $output .= "        }";
    $output .= "    };";
    $output .= "    xhr.open('GET', '" . admin_url('admin-ajax.php') . "?action=update_water_limit', true);";
    $output .= "    xhr.send();";
    $output .= "}";
    $output .= "setInterval(updateWaterLimit, 10000);"; // Update every 10 seconds
    $output .= "updateWaterLimit();"; // Initial call to update immediately
    $output .= "</script>";

    return '<div id="water_limit_output">' . $output . '</div>';
}

// Register shortcode
add_shortcode('update_water_limit', 'update_water_limit_shortcode');

function water_limit_update_form_shortcode() {
    // Check if the form is submitted
    if (isset($_POST['update_limit'])) {
        // Connect to the database
        global $wpdb;

        // Sanitize and retrieve the submitted limit
        $new_limit = intval($_POST['new_limit']);

        // Insert new water limit for user 'zac' into the database
        $insert_query = $wpdb->insert(
            'wp_water_limits',
            array(
                'limit' => $new_limit,
                'user' => 'zac',
                'updatetime' => current_time('mysql')
            ),
            array('%d', '%s', '%s')
        );


        
    } else {
        $message = ''; // Initialize message variable
    }

    // Form HTML
    $form_html = '
    <form method="post">
        <label for="new_limit"></label>
        <input type="number" id="new_limit" name="new_limit" required>
        <input type="submit" name="update_limit" value="Update Limit" style="margin-top:15px;">
    </form>
    ';

    // Output form and message
    return $message . $form_html;
}

// Register shortcode for water limit update form
add_shortcode('water_limit_update_form', 'water_limit_update_form_shortcode');





# Daily Chart

function fetch_hourly_water_data() {
    global $wpdb;

    // Get the current date in the WordPress timezone
    $current_date_wp_timezone = current_time('Y-m-d');

    // Get the WordPress timezone offset in seconds
    $timezone_offset_seconds = get_option('gmt_offset') * 3600;

    // Calculate the start of the current day in the database timezone
    $start_of_day_db_timezone = date('Y-m-d 00:00:00', strtotime($current_date_wp_timezone) + $timezone_offset_seconds);

    // Prepare an array to hold data for each hour of the day (0-23)
    $hourly_data = array_fill(0, 24, 0);

    // Query to retrieve water usage data for the current day
    $query = $wpdb->prepare("SELECT SUM(value) AS total_usage, HOUR(received_at) AS hour 
                             FROM wp_arduino_data 
                             WHERE received_at >= %s 
                             GROUP BY HOUR(received_at)", $start_of_day_db_timezone);
    $hourly_water_data = $wpdb->get_results($query);

    // Fill in the hourly_data array with values from the database
    foreach ($hourly_water_data as $data_point) {
        $hourly_data[$data_point->hour] = $data_point->total_usage;
    }

    return $hourly_data;
}

function output_daily_water_usage_chart_shortcode() {
    // Fetch hourly water data
    $hourly_data = fetch_hourly_water_data();

    // Prepare data for Chart.js
    $labels = range(0, 23); // Hours of the day (0-23)
    $data = array_values($hourly_data); // Use hourly_data array as data

    // Prepare output HTML
    $output = "<p>You have used <span id='total_water_usage'>" . array_sum($hourly_data) . "</span> mL today.</p>";
    $output .= "<canvas id='dailyWaterUsageChart' width='400' height='200'></canvas>";
    $output .= "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
    $output .= "<script>";
    $output .= "document.addEventListener('DOMContentLoaded', function() {";
    $output .= "    var ctx = document.getElementById('dailyWaterUsageChart').getContext('2d');";
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
    $output .= "                        labelString: 'Time (Hours)',"; // Label for X-axis
    $output .= "                    }";
    $output .= "                }],";
    $output .= "            }";
    $output .= "        }";
    $output .= "    });";

    // AJAX request to update chart data
    $output .= "    function updateChart() {";
    $output .= "        var xhr = new XMLHttpRequest();";
    $output .= "        xhr.onreadystatechange = function() {";
    $output .= "            if (xhr.readyState === XMLHttpRequest.DONE) {";
    $output .= "                if (xhr.status === 200) {";
    $output .= "                    var responseData = JSON.parse(xhr.responseText);";
    $output .= "                    var newData = responseData.hourly_data;";
    $output .= "                    var totalUsage = responseData.total_usage;";
    $output .= "                    myChart.data.datasets[0].data = newData;";
    $output .= "                    myChart.update();";
    $output .= "                    document.getElementById('total_water_usage').innerText = totalUsage;";
    $output .= "                } else {";
    $output .= "                    console.error('Error: ' + xhr.status);";
    $output .= "                }";
    $output .= "            }";
    $output .= "        };";
    $output .= "        xhr.open('GET', '" . admin_url('admin-ajax.php') . "?action=update_water_usage_chart', true);";
    $output .= "        xhr.send();";
    $output .= "    }";

    // Call updateChart function every 60 seconds
    $output .= "    setInterval(updateChart, 10000);";
    $output .= "});";
    $output .= "</script>";

    return $output;
}

// AJAX handler to update chart data
function update_water_usage_chart() {
    // Fetch hourly water data
    $hourly_data = fetch_hourly_water_data();

    // Return JSON response with updated data
    wp_send_json(array("hourly_data" => $hourly_data, "total_usage" => array_sum($hourly_data)));
}

// Register AJAX action
add_action('wp_ajax_update_water_usage_chart', 'update_water_usage_chart');
add_action('wp_ajax_nopriv_update_water_usage_chart', 'update_water_usage_chart');

// Register shortcode for daily water usage chart
add_shortcode('daily_water_usage', 'output_daily_water_usage_chart_shortcode');


#########################################################################


function fetch_minute_water_data() {
    global $wpdb;

    // Get the current date and time in the WordPress timezone
    $current_datetime_wp_timezone = current_time('Y-m-d H:i:s');

    // Calculate the start of the past hour in the database timezone
    $start_of_hour_db_timezone = date('Y-m-d H:00:00', strtotime('-1 hour', strtotime($current_datetime_wp_timezone)));

    // Prepare an array to hold data for each minute of the past hour
    $minute_data = array();

    // Query to retrieve water usage data for the past hour with minute-wise breakdown
    $query = $wpdb->prepare("SELECT SUM(value) AS total_usage, MINUTE(received_at) AS minute 
                             FROM wp_arduino_data 
                             WHERE received_at >= %s AND received_at <= %s
                             GROUP BY MINUTE(received_at)", $start_of_hour_db_timezone, $current_datetime_wp_timezone);
    $minute_water_data = $wpdb->get_results($query);

    // Fill in the minute_data array with values from the database
    foreach ($minute_water_data as $data_point) {
        $minute_data[$data_point->minute] = $data_point->total_usage;
    }

    return $minute_data;
}

function output_hourly_water_usage_line_graph_shortcode() {
    // Fetch minute-wise water data for the past hour
    $minute_data = fetch_minute_water_data();

    // Prepare data for Chart.js
    $labels = array_keys($minute_data); // Minutes of the past hour
    $data = array_values($minute_data); // Use minute_data array as data

    // Prepare output HTML
    $output = "<canvas id='hourlyWaterUsageLineGraph' width='400' height='200'></canvas>";
    $output .= "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
    $output .= "<script>";
    $output .= "document.addEventListener('DOMContentLoaded', function() {";
    $output .= "    var ctx = document.getElementById('hourlyWaterUsageLineGraph').getContext('2d');";
    $output .= "    var myChart = new Chart(ctx, {";
    $output .= "        type: 'line',"; // Change chart type to line for hourly data
    $output .= "        data: {";
    $output .= "            labels: " . json_encode($labels) . ",";
    $output .= "            datasets: [{";
    $output .= "                label: 'Water Usage (mL)',";
    $output .= "                data: " . json_encode($data) . ",";
    $output .= "                fill: false,";
    $output .= "                borderColor: 'rgba(54, 162, 235, 1)',";
    $output .= "                borderWidth: 1";
    $output .= "            }]";
    $output .= "        },";
    $output .= "        options: {";
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
    $output .= "                        labelString: 'Time (Minutes)',"; // Label for X-axis
    $output .= "                    }";
    $output .= "                }],";
    $output .= "            }";
    $output .= "        }";
    $output .= "    });";
    $output .= "});";
    $output .= "</script>";

    return $output;
}

// Register shortcode for hourly water usage line graph
add_shortcode('hourly_water_usage_line_graph', 'output_hourly_water_usage_line_graph_shortcode');

// AJAX handler to update line graph data
function update_hourly_water_usage_line_graph() {
    // Fetch minute-wise water data for the past hour
    $minute_data = fetch_minute_water_data();

    // Return JSON response with updated data
    wp_send_json($minute_data);
}

// Register AJAX action
add_action('wp_ajax_update_hourly_water_usage_line_graph', 'update_hourly_water_usage_line_graph');
add_action('wp_ajax_nopriv_update_hourly_water_usage_line_graph', 'update_hourly_water_usage_line_graph');
