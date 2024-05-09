<?php
// Add action to run the function only on the front page
add_action('wp_loaded', 'output_latest_arduino_data_only_front_page');

function output_latest_arduino_data_only_front_page() {
    // Check if the current page is the front page
    if (is_front_page()) {
        // Connect to the database
        global $wpdb;

        // Query to retrieve the latest item from the table
        $latest_data = $wpdb->get_row("SELECT * FROM wp_arduino_data ORDER BY received_at DESC LIMIT 1");

        // Check if there is any data
        if ($latest_data) {
            // Output the value on the page
            echo "Latest Arduino Data: " . $latest_data->value;
        } else {
            // Output a message if no data is found
            echo "No data available.";
        }

        // Fetch all data from the table
        $all_data = $wpdb->get_results("SELECT value, COUNT(*) AS count FROM wp_arduino_data GROUP BY value");

        // Prepare data for Chart.js
        $labels = array();
        $data = array();

        // Extracting data for plotting
        foreach ($all_data as $data_point) {
            $labels[] = $data_point->value;
            $data[] = $data_point->count;
        }

        // Output the Chart.js script with smaller canvas size
        echo "<canvas id='arduinoDataChart' width='400' height='200'></canvas>";
        echo "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
        echo "<script>";
        echo "var ctx = document.getElementById('arduinoDataChart').getContext('2d');";
        echo "var myChart = new Chart(ctx, {";
        echo "    type: 'bar',";
        echo "    data: {";
        echo "        labels: " . json_encode($labels) . ",";
        echo "        datasets: [{";
        echo "            label: 'Occurrences',";
        echo "            data: " . json_encode($data) . ",";
        echo "            backgroundColor: 'rgba(54, 162, 235, 0.2)',";
        echo "            borderColor: 'rgba(54, 162, 235, 1)',";
        echo "            borderWidth: 1";
        echo "        }]";
        echo "    },";
        echo "    options: {";
        echo "        scales: {";
        echo "            yAxes: [{";
        echo "                ticks: {";
        echo "                    beginAtZero: true";
        echo "                }";
        echo "            }]";
        echo "        }";
        echo "    }";
        echo "});";
        echo "</script>";
    }
}
?>