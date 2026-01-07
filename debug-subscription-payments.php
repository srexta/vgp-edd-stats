<?php
/**
 * Debug script to check subscription payment data
 * 
 * Access: yoursite.local/wp-content/plugins/vgp-edd-stats/debug-subscription-payments.php?subscription_id=619
 */

// Load WordPress
require_once(__DIR__ . '/../../../wp-load.php');

// Check if user is logged in and has permissions
if (!is_user_logged_in() || !current_user_can('manage_options')) {
	die('You must be logged in as an administrator to run this test.');
}

// Get subscription ID from query string
$subscription_id = isset($_GET['subscription_id']) ? intval($_GET['subscription_id']) : 0;

if (!$subscription_id) {
	die('Please provide a subscription_id parameter. Example: ?subscription_id=619');
}

echo "<h1>Subscription Payment Debug - ID: $subscription_id</h1>";
echo "<hr>";

global $wpdb;
$prefix = $wpdb->prefix;

// Check subscription exists
echo "<h2>1. Subscription Details</h2>";
$subscription = $wpdb->get_row($wpdb->prepare(
	"SELECT * FROM {$prefix}edd_subscriptions WHERE id = %d",
	$subscription_id
), ARRAY_A);

if ($subscription) {
	echo "<pre>";
	print_r($subscription);
	echo "</pre>";
	
	$sub_start = $subscription['created'];
	$sub_start_year = intval(date('Y', strtotime($sub_start)));
	echo "<p><strong>Subscription Start Year:</strong> $sub_start_year</p>";
} else {
	die("Subscription $subscription_id not found!");
}

// Check which tables exist
echo "<h2>2. Available Tables</h2>";
$order_items_table = $wpdb->get_var("SHOW TABLES LIKE '{$prefix}edd_order_items'");
$ordermeta_table = $wpdb->get_var("SHOW TABLES LIKE '{$prefix}edd_ordermeta'");

echo "edd_order_items: " . ($order_items_table ? "✓ EXISTS" : "✗ NOT FOUND") . "<br>";
echo "edd_ordermeta: " . ($ordermeta_table ? "✓ EXISTS" : "✗ NOT FOUND") . "<br>";

// Try order_items approach
if ($order_items_table) {
	echo "<h2>3. Payments via order_items table</h2>";
	
	$query = $wpdb->prepare(
		"
		SELECT o.id, o.total, o.date_created, o.status, YEAR(o.date_created) as payment_year
		FROM {$prefix}edd_orders o
		INNER JOIN {$prefix}edd_order_items oi ON o.id = oi.order_id
		WHERE oi.subscription_id = %d
		ORDER BY o.date_created ASC
		",
		$subscription_id
	);
	
	echo "<p><strong>Query:</strong></p><pre>" . $query . "</pre>";
	
	$payments = $wpdb->get_results($query, ARRAY_A);
	
	if ($payments) {
		echo "<p><strong>Found " . count($payments) . " payments:</strong></p>";
		echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
		echo "<tr><th>Order ID</th><th>Amount</th><th>Date</th><th>Status</th><th>Year</th></tr>";
		foreach ($payments as $payment) {
			echo "<tr>";
			echo "<td>{$payment['id']}</td>";
			echo "<td>\${$payment['total']}</td>";
			echo "<td>{$payment['date_created']}</td>";
			echo "<td>{$payment['status']}</td>";
			echo "<td>{$payment['payment_year']}</td>";
			echo "</tr>";
		}
		echo "</table>";
		
		// Calculate per-year display
		$payments_per_year = array();
		foreach ($payments as $payment) {
			if ($payment['status'] === 'complete' || $payment['status'] === 'publish' || $payment['status'] === 'edd_subscription') {
				$year_number = intval($payment['payment_year']) - $sub_start_year + 1;
				if ($year_number > 0) {
					if (!isset($payments_per_year[$year_number])) {
						$payments_per_year[$year_number] = 0;
					}
					$payments_per_year[$year_number]++;
				}
			}
		}
		
		echo "<h3>Payments Per Year (since subscription start):</h3>";
		echo "<pre>";
		print_r($payments_per_year);
		echo "</pre>";
		
		$year_labels = array();
		foreach ($payments_per_year as $year_num => $count) {
			$year_labels[] = sprintf('%dx', $year_num);
		}
		$display = implode(', ', $year_labels);
		echo "<p><strong>Display Format:</strong> $display</p>";
		
	} else {
		echo "<p style='color: red;'>No payments found via order_items!</p>";
	}
}

// Try ordermeta approach
if ($ordermeta_table) {
	echo "<h2>4. Payments via ordermeta table</h2>";
	
	$query = $wpdb->prepare(
		"
		SELECT o.id, o.total, o.date_created, o.status, YEAR(o.date_created) as payment_year
		FROM {$prefix}edd_orders o
		INNER JOIN {$prefix}edd_ordermeta om ON o.id = om.edd_order_id
		WHERE om.meta_key = 'subscription_id'
		AND om.meta_value = %d
		ORDER BY o.date_created ASC
		",
		$subscription_id
	);
	
	echo "<p><strong>Query:</strong></p><pre>" . $query . "</pre>";
	
	$payments = $wpdb->get_results($query, ARRAY_A);
	
	if ($payments) {
		echo "<p><strong>Found " . count($payments) . " payments:</strong></p>";
		echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
		echo "<tr><th>Order ID</th><th>Amount</th><th>Date</th><th>Status</th><th>Year</th></tr>";
		foreach ($payments as $payment) {
			echo "<tr>";
			echo "<td>{$payment['id']}</td>";
			echo "<td>\${$payment['total']}</td>";
			echo "<td>{$payment['date_created']}</td>";
			echo "<td>{$payment['status']}</td>";
			echo "<td>{$payment['payment_year']}</td>";
			echo "</tr>";
		}
		echo "</table>";
	} else {
		echo "<p style='color: red;'>No payments found via ordermeta!</p>";
	}
}

// Check order_items structure
if ($order_items_table) {
	echo "<h2>5. Order Items for this subscription</h2>";
	$order_items = $wpdb->get_results($wpdb->prepare(
		"SELECT * FROM {$prefix}edd_order_items WHERE subscription_id = %d LIMIT 10",
		$subscription_id
	), ARRAY_A);
	
	if ($order_items) {
		echo "<p>Found " . count($order_items) . " order items:</p>";
		echo "<pre>";
		print_r($order_items);
		echo "</pre>";
	} else {
		echo "<p style='color: red;'>No order items found with subscription_id = $subscription_id</p>";
		
		// Check if subscription_id column exists
		$columns = $wpdb->get_results("SHOW COLUMNS FROM {$prefix}edd_order_items", ARRAY_A);
		echo "<h3>Available columns in edd_order_items:</h3><pre>";
		foreach ($columns as $col) {
			echo $col['Field'] . " (" . $col['Type'] . ")\n";
		}
		echo "</pre>";
	}
}

echo "<hr>";
echo "<p><small>Debug completed at " . date('Y-m-d H:i:s') . "</small></p>";
