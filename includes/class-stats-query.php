<?php
/**
 * Stats query functionality.
 *
 * @package VGP_EDD_Stats
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats query class.
 */
class VGP_EDD_Stats_Query {

	/**
	 * Cache group for transients.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'vgp_edd_stats_';

	/**
	 * Historical data cutoff date for cohort analysis.
	 * Customers created before this date are excluded from renewal rate calculations.
	 *
	 * @var string Date in Y-m-d format.
	 */
	const HISTORICAL_DATA_CUTOFF_DATE = '2015-01-01';

	/**
	 * Renewal window in months for cohort analysis.
	 * Defines the period after customer signup when renewals are measured.
	 *
	 * @var int Number of months.
	 */
	const RENEWAL_WINDOW_MONTHS = 12;

	/**
	 * Purchase value threshold for excluding single-purchase customers.
	 * Customers with purchase_count = 1 and purchase_value >= this amount are excluded
	 * from renewal rate calculations (typically enterprise/lifetime purchases).
	 *
	 * @var float Purchase value threshold.
	 */
	const EXCLUSION_PURCHASE_VALUE_THRESHOLD = 847.0;

	/**
	 * Development database connection.
	 *
	 * @var wpdb|null
	 */
	private static $dev_db = null;

	/**
	 * Check if development mode is enabled.
	 *
	 * @return bool True if dev mode is enabled.
	 */
	private static function is_dev_mode() {
		// Check if dev-config.php exists and has been loaded
		if ( file_exists( VGP_EDD_STATS_PLUGIN_DIR . 'dev-config.php' ) ) {
			require_once VGP_EDD_STATS_PLUGIN_DIR . 'dev-config.php';
		}

		return defined( 'VGP_EDD_STATS_DEV_MODE' ) && VGP_EDD_STATS_DEV_MODE;
	}

	/**
	 * Get database connection (dev or production).
	 *
	 * @return wpdb Database connection.
	 */
	private static function get_db() {
		// Return production database if not in dev mode
		if ( ! self::is_dev_mode() ) {
			global $wpdb;
			return $wpdb;
		}

		// Initialize dev database connection if needed
		if ( null === self::$dev_db ) {
			$db_host = defined( 'VGP_EDD_DEV_DB_HOST' ) ? VGP_EDD_DEV_DB_HOST : 'localhost';
			$db_name = defined( 'VGP_EDD_DEV_DB_NAME' ) ? VGP_EDD_DEV_DB_NAME : 'vgp_edd_dev';
			$db_user = defined( 'VGP_EDD_DEV_DB_USER' ) ? VGP_EDD_DEV_DB_USER : 'root';
			$db_pass = defined( 'VGP_EDD_DEV_DB_PASSWORD' ) ? VGP_EDD_DEV_DB_PASSWORD : 'root';

			// Create new wpdb instance for dev database
			self::$dev_db = new wpdb( $db_user, $db_pass, $db_name, $db_host );

			// Ensure table prefix and core table names are set for this connection
			$dev_prefix = defined( 'VGP_EDD_DEV_DB_PREFIX' ) ? VGP_EDD_DEV_DB_PREFIX : 'wp_';
			if ( method_exists( self::$dev_db, 'set_prefix' ) ) {
				self::$dev_db->set_prefix( $dev_prefix );
			} else {
				self::$dev_db->prefix = $dev_prefix; // Fallback
			}

			// Check if connection succeeded
			if ( ! self::$dev_db->dbh ) {
				// Connection failed, fall back to production database
				error_log( 'VGP EDD Stats: Failed to connect to dev database. Falling back to production database.' );
				if ( ! empty( self::$dev_db->error ) ) {
					error_log( 'VGP EDD Stats: Connection error: ' . self::$dev_db->error->get_error_message() );
				}
				global $wpdb;
				self::$dev_db = $wpdb;
			} else {
				// Set charset for dev database
				self::$dev_db->set_charset( self::$dev_db->dbh );
			}
		}

		return self::$dev_db;
	}

	/**
	 * Get cache duration in seconds.
	 *
	 * @return int Cache duration.
	 */
	private static function get_cache_duration() {
		return (int) get_option( 'vgp_edd_stats_cache_duration', 3600 );
	}

	/**
	 * Get cached query result or execute query.
	 *
	 * @param string $cache_key Cache key.
	 * @param string $query     SQL query.
	 * @param string $type      Query type (get_results, get_var, get_col).
	 * @return mixed Query results.
	 */
	private static function get_cached( $cache_key, $query, $type = 'get_results' ) {
		$db = self::get_db();

		$cache_duration = self::get_cache_duration();

		// Add dev mode suffix to cache key to prevent collisions
		if ( self::is_dev_mode() ) {
			$cache_key .= '_dev';
		}

		// Try to get cached data.
		if ( $cache_duration > 0 ) {
			$cached = get_transient( self::CACHE_GROUP . $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Execute query.
		switch ( $type ) {
			case 'get_var':
				$results = $db->get_var( $query );
				break;
			case 'get_col':
				$results = $db->get_col( $query );
				break;
			default:
				$results = $db->get_results( $query, ARRAY_A );
				break;
		}

		// Cache the results.
		if ( $cache_duration > 0 ) {
			set_transient( self::CACHE_GROUP . $cache_key, $results, $cache_duration );
		}

		return $results;
	}

	/**
	 * Get table prefix.
	 *
	 * @return string Table prefix.
	 */
	private static function get_table_prefix() {
		if ( self::is_dev_mode() && defined( 'VGP_EDD_DEV_DB_PREFIX' ) ) {
			return VGP_EDD_DEV_DB_PREFIX;
		}

		global $wpdb;
		return $wpdb->prefix;
	}

	// =========================
	// CUSTOMERS AND REVENUE
	// =========================

	/**
	 * Get new customers by month.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Query results.
	 */
	public static function get_new_customers_by_month( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(date_created, '%Y-%m-01') AS date,
				DATE_FORMAT(date_created, '%M %Y') AS label,
				COUNT(*) AS value
			FROM {$wpdb->prefix}edd_customers
			WHERE date_created IS NOT NULL
			{$where}
			GROUP BY
				YEAR(date_created),
				MONTH(date_created)
			ORDER BY date
		";

		return self::get_cached( 'customers_by_month_' . md5( $query ), $query );
	}

	/**
	 * Get new customers YoY change.
	 *
	 * @return array Query results with current year, last year, and percent change.
	 */
	public static function get_new_customers_yoy_change() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				SUM(CASE
					WHEN YEAR(date_created) = YEAR(CURDATE()) THEN 1
					ELSE 0
				END) AS current_year,
				SUM(CASE
					WHEN YEAR(date_created) = YEAR(CURDATE()) - 1 THEN 1
					ELSE 0
				END) AS last_year
			FROM {$wpdb->prefix}edd_customers
			WHERE YEAR(date_created) IN (YEAR(CURDATE()), YEAR(CURDATE()) - 1)
		";

		$result = self::get_cached( 'customers_yoy', $query );

		if ( empty( $result ) || ! isset( $result[0] ) ) {
			return array(
				'current_year' => 0,
				'last_year'    => 0,
				'change'       => 0,
			);
		}

		$current = (int) $result[0]['current_year'];
		$last    = (int) $result[0]['last_year'];
		$change  = $last > 0 ? ( ( $current - $last ) / $last ) * 100 : 0;

		return array(
			'current_year' => $current,
			'last_year'    => $last,
			'change'       => round( $change, 2 ),
		);
	}

	/**
	 * Get revenue by month (new vs recurring).
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Query results.
	 */
	public static function get_revenue_by_month( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(o.date_created, '%Y-%m-01') AS date,
				DATE_FORMAT(o.date_created, '%M %Y') AS label,
				SUM(CASE WHEN o.type = 'sale' THEN o.total ELSE 0 END) AS new_revenue,
				SUM(CASE WHEN o.type = 'renewal' THEN o.total ELSE 0 END) AS recurring_revenue,
				SUM(o.total) AS total_revenue
			FROM {$wpdb->prefix}edd_orders o
			WHERE o.type IN ('sale', 'renewal')
			{$where}
			GROUP BY
				YEAR(o.date_created),
				MONTH(o.date_created)
			ORDER BY date
		";

		return self::get_cached( 'revenue_by_month_' . md5( $query ), $query );
	}

	/**
	 * Get refunded revenue by month.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Query results.
	 */
	public static function get_refunded_revenue_by_month( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(o.date_created, '%Y-%m-01') AS date,
				DATE_FORMAT(o.date_created, '%M %Y') AS label,
				ABS(SUM(o.total)) AS value
			FROM {$wpdb->prefix}edd_orders o
			WHERE o.status = 'refunded'
			{$where}
			GROUP BY
				YEAR(o.date_created),
				MONTH(o.date_created)
			ORDER BY date
		";

		return self::get_cached( 'refunded_revenue_' . md5( $query ), $query );
	}

	// =========================
	// MRR AND GROWTH
	// =========================

	/**
	 * Get MRR by month.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Query results.
	 */
	public static function get_mrr_by_month( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND s.status != 'pending'";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND s.created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND s.created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(s.created, '%Y-%m-01') AS date,
				DATE_FORMAT(s.created, '%M %Y') AS label,
				COUNT(DISTINCT s.id) AS subscriptions,
				ROUND(SUM(s.initial_amount / 12), 2) AS mrr
			FROM {$wpdb->prefix}edd_subscriptions s
			WHERE 1=1
			{$where}
			AND NOT EXISTS (
				SELECT 1
				FROM {$wpdb->prefix}edd_orders o
				WHERE ( o.parent = s.parent_payment_id OR o.id = s.parent_payment_id )
				AND o.status = 'refunded'
			)
			GROUP BY
				YEAR(s.created),
				MONTH(s.created)
			ORDER BY date
		";

		return self::get_cached( 'mrr_by_month_' . md5( $query ), $query );
	}

	/**
	 * Get current MRR breakdown (new, existing, churned).
	 *
	 * @return array Current month MRR breakdown.
	 */
	public static function get_current_mrr_breakdown() {
		$wpdb = self::get_db();

		// New MRR (subscriptions started this month).
		$new_mrr_query = "
			SELECT COALESCE(ROUND(SUM(initial_amount / 12), 2), 0) AS new_mrr
			FROM {$wpdb->prefix}edd_subscriptions
			WHERE DATE_FORMAT(created, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
			AND status != 'pending'
		";

		$new_mrr = self::get_cached( 'new_mrr_current', $new_mrr_query, 'get_var' );

		// Churned MRR (subscriptions cancelled/expired this month).
		$churned_mrr_query = "
			SELECT COALESCE(ROUND(SUM(initial_amount / 12), 2), 0) AS churned_mrr
			FROM {$wpdb->prefix}edd_subscriptions
			WHERE DATE_FORMAT(expiration, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
			AND status IN ('cancelled', 'expired')
		";

		$churned_mrr = self::get_cached( 'churned_mrr_current', $churned_mrr_query, 'get_var' );

		// Existing MRR (active subscriptions from previous months).
		$existing_mrr_query = "
			SELECT COALESCE(ROUND(SUM(initial_amount / 12), 2), 0) AS existing_mrr
			FROM {$wpdb->prefix}edd_subscriptions
			WHERE DATE_FORMAT(created, '%Y-%m') < DATE_FORMAT(CURDATE(), '%Y-%m')
			AND status = 'active'
		";

		$existing_mrr = self::get_cached( 'existing_mrr_current', $existing_mrr_query, 'get_var' );

		return array(
			'new_mrr'      => (float) $new_mrr,
			'existing_mrr' => (float) $existing_mrr,
			'churned_mrr'  => (float) $churned_mrr,
			'net_mrr'      => (float) $new_mrr + (float) $existing_mrr - (float) $churned_mrr,
		);
	}

	// =========================
	// RENEWALS AND CANCELLATIONS
	// =========================

	/**
	 * Get renewal rates by month.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Query results.
	 */
    public static function get_renewal_rates_by_month( $start_date = null, $end_date = null ) {
        $wpdb = self::get_db();

        // Optional filter based on renewal month (12 months after signup)
        $filter = '';
        if ( $start_date ) {
            $filter .= $wpdb->prepare(
                " AND DATE_FORMAT(DATE_ADD(c.date_created, INTERVAL 12 MONTH), '%%Y-%%m') >= %s",
                substr( $start_date, 0, 7 )
            );
        }
        if ( $end_date ) {
            $filter .= $wpdb->prepare(
                " AND DATE_FORMAT(DATE_ADD(c.date_created, INTERVAL 12 MONTH), '%%Y-%%m') <= %s",
                substr( $end_date, 0, 7 )
            );
        }

        $query = "
            SELECT
                renewal_month AS month_year,
                DATE_FORMAT(STR_TO_DATE(CONCAT(renewal_month, '-01'), '%Y-%m-%d'), '%M %Y') AS label,
                ROUND(AVG(renewal_rate), 2) AS renewal_rate
            FROM (
                SELECT
                    DATE_FORMAT(DATE_ADD(c.date_created, INTERVAL 12 MONTH), '%Y-%m') AS renewal_month,
                    100.0 * COUNT(DISTINCT CASE
                        WHEN o.status IN ('complete','edd_subscription')
                         AND o.date_created >= DATE_ADD(c.date_created, INTERVAL 12 MONTH)
                         AND o.date_created <  DATE_ADD(c.date_created, INTERVAL 13 MONTH)
                         THEN o.customer_id END)
                    / NULLIF(COUNT(DISTINCT c.id), 0) AS renewal_rate
                FROM {$wpdb->prefix}edd_customers c
                LEFT JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
                WHERE c.date_created >= '2015-01-01'
                  AND c.date_created <= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                  AND NOT (c.purchase_count = 1 AND c.purchase_value >= 847)
                  {$filter}
                GROUP BY DATE_FORMAT(DATE_ADD(c.date_created, INTERVAL 12 MONTH), '%Y-%m')
            ) AS r
            GROUP BY renewal_month
            ORDER BY renewal_month
        ";

        return self::get_cached( 'renewal_rates_' . md5( $query ), $query );
    }

	/**
	 * Get upcoming renewals.
	 *
	 * @param int $days Number of days to look ahead.
	 * @return array Query results with count and estimated revenue.
	 */
	public static function get_upcoming_renewals( $days = 30 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				COUNT(DISTINCT id) AS count,
				ROUND(SUM(recurring_amount), 2) AS estimated_revenue
			FROM {$wpdb->prefix}edd_subscriptions
			WHERE status = 'active'
			AND expiration >= CURDATE()
			AND expiration <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
			",
			$days
		);

		$result = self::get_cached( "upcoming_renewals_{$days}", $query );

		if ( empty( $result ) || ! isset( $result[0] ) ) {
			return array(
				'count'              => 0,
				'estimated_revenue'  => 0,
			);
		}

		return array(
			'count'             => (int) $result[0]['count'],
			'estimated_revenue' => (float) $result[0]['estimated_revenue'],
		);
	}

	// =========================
    // REFUNDS
    // =========================

	/**
	 * Get refund rates by month.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Query results.
	 */
    public static function get_refund_rates_by_month( $start_date = null, $end_date = null ) {
        $wpdb = self::get_db();

		$where = '';
            if ( $start_date ) {
                $where .= $wpdb->prepare( ' AND DATE_FORMAT(date_created, "%%Y-%%m") >= %s', substr( $start_date, 0, 7 ) );
            }
            if ( $end_date ) {
                $where .= $wpdb->prepare( ' AND DATE_FORMAT(date_created, "%%Y-%%m") <= %s', substr( $end_date, 0, 7 ) );
            }

			// Calculate refund rate as refunded count divided by (completed + refunded) count per month
        $query = "
                SELECT
                    month_year,
                    DATE_FORMAT(STR_TO_DATE(CONCAT(month_year, '-01'), '%Y-%m-%d'), '%M %Y') AS label,
                    COALESCE(ROUND(
                        100.0 * SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) /
                        NULLIF(SUM(CASE WHEN status IN ('complete','refunded') THEN 1 ELSE 0 END), 0),
                        2
                    ), 0) AS refund_rate
                FROM (
                    SELECT
                        DATE_FORMAT(date_created, '%Y-%m') AS month_year,
                        status
                    FROM {$wpdb->prefix}edd_orders
                    WHERE 1=1
                    {$where}
                ) AS orders
                GROUP BY month_year
                ORDER BY month_year
            ";

        return self::get_cached( 'refund_rates_' . md5( $query ), $query );
    }

    /**
     * Get new customer refund rates by year.
     * A "new" order is considered where parent = 0.
     *
     * @param string $start_date Optional start date (Y-m-d).
     * @param string $end_date   Optional end date (Y-m-d).
     * @return array Yearly refund metrics for new customers.
     */
    public static function get_new_customer_refund_rates_by_year( $start_date = null, $end_date = null ) {
        $wpdb = self::get_db();

        $where = '';
        if ( $start_date ) {
            $where .= $wpdb->prepare( ' AND date_created >= %s', $start_date );
        }
        if ( $end_date ) {
            $where .= $wpdb->prepare( ' AND date_created <= %s', $end_date );
        }

        // Default behavior: all years before current year if no explicit range provided
        if ( ! $start_date && ! $end_date ) {
            $where .= " AND date_created < DATE(CONCAT(YEAR(CURRENT_DATE), '-01-01'))";
        }

        $query = "
            SELECT
                YEAR(date_created) AS year,
                COUNT(CASE WHEN parent = 0 THEN 1 ELSE NULL END) AS new_orders,
                COUNT(CASE WHEN parent = 0 AND status = 'refunded' THEN 1 ELSE NULL END) AS refunded_orders,
                ROUND(
                    100.0 * COUNT(CASE WHEN parent = 0 AND status = 'refunded' THEN 1 ELSE NULL END)
                    / NULLIF(COUNT(CASE WHEN parent = 0 THEN 1 ELSE NULL END), 0),
                    2
                ) AS refund_rate
            FROM {$wpdb->prefix}edd_orders
            WHERE 1=1
            {$where}
            GROUP BY YEAR(date_created)
            ORDER BY YEAR(date_created)
        ";

        return self::get_cached( 'new_customer_refunds_year_' . md5( $query ), $query );
    }

	// =========================
	// SOFTWARE LICENSING
	// =========================

	/**
	 * Get top licenses by activation count.
	 *
	 * @param int $limit Number of results to return.
	 * @return array Query results.
	 */
	public static function get_top_licenses( $limit = 20 ) {
		$wpdb = self::get_db();

		// Check if EDD Software Licensing tables exist.
		$table_name = $wpdb->prefix . 'edd_licenses';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			return array();
		}

		$query = $wpdb->prepare(
			"
			SELECT
				l.id AS license_id,
				l.license_key,
				COUNT(la.site_name) AS activation_count,
				d.post_title AS download_name
			FROM {$wpdb->prefix}edd_licenses l
			LEFT JOIN {$wpdb->prefix}edd_license_activations la ON l.id = la.license_id
			LEFT JOIN {$wpdb->posts} d ON l.download_id = d.ID
			WHERE l.status != 'disabled'
			GROUP BY l.id
			ORDER BY activation_count DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( "top_licenses_{$limit}", $query );
	}

	// =========================
	// CUSTOMER LIFETIME VALUE
	// =========================

	/**
	 * Get customer lifetime values.
	 *
	 * @param string $start_date Start date for filtering customer creation.
	 * @param string $end_date   End date for filtering customer creation.
	 * @param int    $limit      Number of results to return (default: 100).
	 * @return array Customer CLV data with purchase_count, total_spent, avg_order_value, days_active.
	 */
	public static function get_customer_lifetime_values( $start_date = null, $end_date = null, $limit = 100 ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created <= %s', $end_date );
		}

		$query = $wpdb->prepare(
			"
			SELECT
				c.id AS customer_id,
				c.email,
				c.name,
				c.date_created AS signup_date,
				COUNT(DISTINCT o.id) AS purchase_count,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS total_spent,
				ROUND(AVG(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE NULL END), 2) AS avg_order_value,
				DATEDIFF(CURDATE(), c.date_created) AS days_active,
				MAX(o.date_created) AS last_purchase_date
			FROM {$wpdb->prefix}edd_customers c
			LEFT JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
			WHERE c.date_created IS NOT NULL
			{$where}
			GROUP BY c.id
			HAVING purchase_count > 0
			ORDER BY total_spent DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( 'customer_clv_' . md5( $query ), $query );
	}

	/**
	 * Get CLV by customer cohort (monthly signup cohorts).
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array CLV data segmented by signup month cohort.
	 */
	public static function get_clv_by_cohort( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(c.date_created, '%Y-%m-01') AS cohort_date,
				DATE_FORMAT(c.date_created, '%M %Y') AS cohort_label,
				COUNT(DISTINCT c.id) AS customer_count,
				ROUND(AVG(customer_totals.total_spent), 2) AS avg_clv,
				ROUND(AVG(customer_totals.purchase_count), 2) AS avg_purchases,
				ROUND(AVG(customer_totals.days_active), 0) AS avg_days_active
			FROM {$wpdb->prefix}edd_customers c
			INNER JOIN (
				SELECT
					c2.id,
					SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) AS total_spent,
					COUNT(DISTINCT o.id) AS purchase_count,
					DATEDIFF(CURDATE(), c2.date_created) AS days_active
				FROM {$wpdb->prefix}edd_customers c2
				LEFT JOIN {$wpdb->prefix}edd_orders o ON c2.id = o.customer_id
				GROUP BY c2.id
			) AS customer_totals ON c.id = customer_totals.id
			WHERE c.date_created IS NOT NULL
			{$where}
			GROUP BY
				YEAR(c.date_created),
				MONTH(c.date_created)
			ORDER BY cohort_date
		";

		return self::get_cached( 'clv_by_cohort_' . md5( $query ), $query );
	}

	/**
	 * Get CLV distribution across percentiles.
	 *
	 * @return array CLV distribution by percentile segments.
	 */
	public static function get_clv_distribution() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				CASE
					WHEN total_spent >= percentile_90 THEN 'Top 10%'
					WHEN total_spent >= percentile_75 THEN '75-90%'
					WHEN total_spent >= percentile_50 THEN '50-75%'
					WHEN total_spent >= percentile_25 THEN '25-50%'
					ELSE 'Bottom 25%'
				END AS segment,
				COUNT(*) AS customer_count,
				ROUND(AVG(total_spent), 2) AS avg_clv,
				ROUND(SUM(total_spent), 2) AS total_revenue
			FROM (
				SELECT
					c.id,
					SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) AS total_spent,
					(SELECT SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 FROM {$wpdb->prefix}edd_customers c2
					 LEFT JOIN {$wpdb->prefix}edd_orders o2 ON c2.id = o2.customer_id
					 GROUP BY c2.id
					 ORDER BY SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.9) FROM {$wpdb->prefix}edd_customers)
					) AS percentile_90,
					(SELECT SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 FROM {$wpdb->prefix}edd_customers c2
					 LEFT JOIN {$wpdb->prefix}edd_orders o2 ON c2.id = o2.customer_id
					 GROUP BY c2.id
					 ORDER BY SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.75) FROM {$wpdb->prefix}edd_customers)
					) AS percentile_75,
					(SELECT SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 FROM {$wpdb->prefix}edd_customers c2
					 LEFT JOIN {$wpdb->prefix}edd_orders o2 ON c2.id = o2.customer_id
					 GROUP BY c2.id
					 ORDER BY SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.5) FROM {$wpdb->prefix}edd_customers)
					) AS percentile_50,
					(SELECT SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 FROM {$wpdb->prefix}edd_customers c2
					 LEFT JOIN {$wpdb->prefix}edd_orders o2 ON c2.id = o2.customer_id
					 GROUP BY c2.id
					 ORDER BY SUM(CASE WHEN o2.status IN ('complete', 'edd_subscription') THEN o2.total ELSE 0 END)
					 LIMIT 1 OFFSET (SELECT FLOOR(COUNT(*) * 0.25) FROM {$wpdb->prefix}edd_customers)
					) AS percentile_25
				FROM {$wpdb->prefix}edd_customers c
				LEFT JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				GROUP BY c.id
				HAVING total_spent > 0
			) AS customer_clv
			GROUP BY segment
			ORDER BY
				CASE segment
					WHEN 'Top 10%' THEN 1
					WHEN '75-90%' THEN 2
					WHEN '50-75%' THEN 3
					WHEN '25-50%' THEN 4
					WHEN 'Bottom 25%' THEN 5
				END
		";

		return self::get_cached( 'clv_distribution', $query );
	}

	// =========================
	// RFM SEGMENTATION
	// =========================

	/**
	 * Get RFM (Recency, Frequency, Monetary) customer segments.
	 *
	 * @return array Customers segmented by RFM scores.
	 */
	public static function get_rfm_segments() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				c.id AS customer_id,
				c.email,
				c.name,
				DATEDIFF(CURDATE(), MAX(o.date_created)) AS recency_days,
				COUNT(DISTINCT o.id) AS frequency,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS monetary,
				CASE
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 30 THEN 5
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 60 THEN 4
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 90 THEN 3
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 180 THEN 2
					ELSE 1
				END AS recency_score,
				CASE
					WHEN COUNT(DISTINCT o.id) >= 10 THEN 5
					WHEN COUNT(DISTINCT o.id) >= 7 THEN 4
					WHEN COUNT(DISTINCT o.id) >= 4 THEN 3
					WHEN COUNT(DISTINCT o.id) >= 2 THEN 2
					ELSE 1
				END AS frequency_score,
				CASE
					WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 5000 THEN 5
					WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 2000 THEN 4
					WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 1000 THEN 3
					WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 500 THEN 2
					ELSE 1
				END AS monetary_score
			FROM {$wpdb->prefix}edd_customers c
			INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
			WHERE o.status IN ('complete', 'edd_subscription')
			GROUP BY c.id
			HAVING frequency > 0
			ORDER BY recency_score DESC, frequency_score DESC, monetary_score DESC
		";

		return self::get_cached( 'rfm_segments', $query );
	}

	/**
	 * Get segment performance metrics.
	 *
	 * @return array Revenue and customer count by RFM segment.
	 */
	public static function get_segment_performance() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				CASE
					WHEN (recency_score + frequency_score + monetary_score) >= 13 THEN 'Champions'
					WHEN (recency_score + frequency_score + monetary_score) >= 10 AND recency_score >= 4 THEN 'Loyal Customers'
					WHEN monetary_score >= 4 AND (recency_score + frequency_score) >= 6 THEN 'Big Spenders'
					WHEN recency_score >= 4 AND frequency_score <= 2 THEN 'Recent Customers'
					WHEN frequency_score >= 4 AND recency_score <= 2 THEN 'At Risk'
					WHEN recency_score <= 2 AND frequency_score <= 2 THEN 'Lost'
					ELSE 'Potential'
				END AS segment,
				COUNT(*) AS customer_count,
				ROUND(SUM(monetary), 2) AS total_revenue,
				ROUND(AVG(monetary), 2) AS avg_revenue,
				ROUND(AVG(frequency), 2) AS avg_purchases,
				ROUND(AVG(recency_days), 0) AS avg_days_since_purchase
			FROM (
				SELECT
					c.id,
					DATEDIFF(CURDATE(), MAX(o.date_created)) AS recency_days,
					COUNT(DISTINCT o.id) AS frequency,
					SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) AS monetary,
					CASE
						WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 30 THEN 5
						WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 60 THEN 4
						WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 90 THEN 3
						WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 180 THEN 2
						ELSE 1
					END AS recency_score,
					CASE
						WHEN COUNT(DISTINCT o.id) >= 10 THEN 5
						WHEN COUNT(DISTINCT o.id) >= 7 THEN 4
						WHEN COUNT(DISTINCT o.id) >= 4 THEN 3
						WHEN COUNT(DISTINCT o.id) >= 2 THEN 2
						ELSE 1
					END AS frequency_score,
					CASE
						WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 5000 THEN 5
						WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 2000 THEN 4
						WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 1000 THEN 3
						WHEN SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) >= 500 THEN 2
						ELSE 1
					END AS monetary_score
				FROM {$wpdb->prefix}edd_customers c
				INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				WHERE o.status IN ('complete', 'edd_subscription')
				GROUP BY c.id
			) AS rfm_data
			GROUP BY segment
			ORDER BY total_revenue DESC
		";

		return self::get_cached( 'segment_performance', $query );
	}

	// =========================
	// CUSTOMER HEALTH & ENGAGEMENT
	// =========================

	/**
	 * Get customer health scores.
	 *
	 * @param int $limit Number of results to return (default: 100).
	 * @return array Customers with health scores based on activity, spending, engagement.
	 */
	public static function get_customer_health_scores( $limit = 100 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				c.id AS customer_id,
				c.email,
				c.name,
				DATEDIFF(CURDATE(), MAX(o.date_created)) AS days_since_purchase,
				COUNT(DISTINCT o.id) AS total_purchases,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS total_spent,
				(SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s WHERE s.customer_id = c.id AND s.status = 'active') AS active_subscriptions,
				CASE
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 30
						AND (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s WHERE s.customer_id = c.id AND s.status = 'active') > 0
						THEN 'Excellent'
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 60
						AND COUNT(DISTINCT o.id) >= 2
						THEN 'Good'
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 90
						THEN 'Fair'
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 180
						THEN 'At Risk'
					ELSE 'Poor'
				END AS health_status,
				CASE
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 30
						AND (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s WHERE s.customer_id = c.id AND s.status = 'active') > 0
						THEN 95
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 60
						AND COUNT(DISTINCT o.id) >= 2
						THEN 75
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 90
						THEN 50
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) <= 180
						THEN 25
					ELSE 10
				END AS health_score
			FROM {$wpdb->prefix}edd_customers c
			INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
			WHERE o.status IN ('complete', 'edd_subscription')
			GROUP BY c.id
			ORDER BY health_score DESC, total_spent DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( "customer_health_{$limit}", $query );
	}

	/**
	 * Get at-risk customers (likely to churn).
	 *
	 * @param int $limit Number of results to return (default: 50).
	 * @return array Customers identified as at risk of churning.
	 */
	public static function get_at_risk_customers( $limit = 50 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				c.id AS customer_id,
				c.email,
				c.name,
				DATEDIFF(CURDATE(), MAX(o.date_created)) AS days_since_purchase,
				COUNT(DISTINCT o.id) AS total_purchases,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS total_spent,
				MAX(o.date_created) AS last_purchase_date,
				(SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s
				 WHERE s.customer_id = c.id
				 AND s.status IN ('cancelled', 'expired')
				 AND s.expiration >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
				) AS recent_cancellations,
				CASE
					WHEN (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s
						  WHERE s.customer_id = c.id AND s.status IN ('cancelled', 'expired')
						  AND s.expiration >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) > 0
						THEN 'High'
					WHEN DATEDIFF(CURDATE(), MAX(o.date_created)) > 120
						AND COUNT(DISTINCT o.id) >= 3
						THEN 'Medium'
					ELSE 'Low'
				END AS churn_risk
			FROM {$wpdb->prefix}edd_customers c
			INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
			WHERE o.status IN ('complete', 'edd_subscription')
			GROUP BY c.id
			HAVING
				(days_since_purchase > 90 AND total_purchases >= 2)
				OR recent_cancellations > 0
			ORDER BY
				CASE churn_risk
					WHEN 'High' THEN 1
					WHEN 'Medium' THEN 2
					WHEN 'Low' THEN 3
				END,
				total_spent DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( "at_risk_customers_{$limit}", $query );
	}

	/**
	 * Get customer engagement metrics.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Engagement metrics by month.
	 */
	public static function get_customer_engagement_metrics( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(o.date_created, '%Y-%m-01') AS date,
				DATE_FORMAT(o.date_created, '%M %Y') AS label,
				COUNT(DISTINCT o.customer_id) AS active_customers,
				COUNT(DISTINCT o.id) AS total_orders,
				ROUND(AVG(o.total), 2) AS avg_order_value,
				ROUND(COUNT(DISTINCT o.id) / COUNT(DISTINCT o.customer_id), 2) AS orders_per_customer
			FROM {$wpdb->prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
			{$where}
			GROUP BY
				YEAR(o.date_created),
				MONTH(o.date_created)
			ORDER BY date
		";

		return self::get_cached( 'engagement_metrics_' . md5( $query ), $query );
	}

	// =========================
	// CUSTOMER JOURNEY
	// =========================

	/**
	 * Get customer acquisition channels.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Customer counts by acquisition channel.
	 */
	public static function get_customer_acquisition_channels( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created <= %s', $end_date );
		}

		// Note: This queries customer notes for UTM parameters or referrer data
		// Adjust based on how your EDD installation tracks acquisition sources
		$query = "
			SELECT
				CASE
					WHEN cn.content LIKE '%utm_source=google%' THEN 'Google'
					WHEN cn.content LIKE '%utm_source=facebook%' THEN 'Facebook'
					WHEN cn.content LIKE '%utm_source=twitter%' THEN 'Twitter'
					WHEN cn.content LIKE '%utm_source=email%' THEN 'Email'
					WHEN cn.content LIKE '%utm_source=affiliate%' THEN 'Affiliate'
					WHEN cn.content LIKE '%referrer%' THEN 'Referral'
					ELSE 'Direct/Unknown'
				END AS channel,
				COUNT(DISTINCT c.id) AS customer_count,
				ROUND(SUM(customer_revenue.total_spent), 2) AS total_revenue,
				ROUND(AVG(customer_revenue.total_spent), 2) AS avg_revenue_per_customer
			FROM {$wpdb->prefix}edd_customers c
			LEFT JOIN {$wpdb->prefix}edd_customermeta cm ON c.id = cm.customer_id
			LEFT JOIN {$wpdb->prefix}edd_customer_email_addresses cea ON c.id = cea.customer_id
			LEFT JOIN {$wpdb->comments} cn ON cn.comment_author_email = c.email
				AND cn.comment_type = 'edd_payment_note'
			LEFT JOIN (
				SELECT
					customer_id,
					SUM(CASE WHEN status IN ('complete', 'edd_subscription') THEN total ELSE 0 END) AS total_spent
				FROM {$wpdb->prefix}edd_orders
				GROUP BY customer_id
			) AS customer_revenue ON c.id = customer_revenue.customer_id
			WHERE c.date_created IS NOT NULL
			{$where}
			GROUP BY channel
			ORDER BY customer_count DESC
		";

		return self::get_cached( 'acquisition_channels_' . md5( $query ), $query );
	}

	/**
	 * Get time to first purchase distribution.
	 *
	 * @param string $start_date Start date for customer creation filter.
	 * @param string $end_date   End date for customer creation filter.
	 * @return array Distribution of days from signup to first purchase.
	 */
	public static function get_time_to_first_purchase( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				CASE
					WHEN days_to_first_purchase = 0 THEN 'Same Day'
					WHEN days_to_first_purchase <= 1 THEN '1 Day'
					WHEN days_to_first_purchase <= 7 THEN '2-7 Days'
					WHEN days_to_first_purchase <= 30 THEN '8-30 Days'
					WHEN days_to_first_purchase <= 90 THEN '31-90 Days'
					ELSE '90+ Days'
				END AS time_segment,
				COUNT(*) AS customer_count,
				ROUND(AVG(days_to_first_purchase), 1) AS avg_days
			FROM (
				SELECT
					c.id,
					DATEDIFF(MIN(o.date_created), c.date_created) AS days_to_first_purchase
				FROM {$wpdb->prefix}edd_customers c
				INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				WHERE o.status IN ('complete', 'edd_subscription')
				AND c.date_created IS NOT NULL
				{$where}
				GROUP BY c.id
			) AS customer_conversion
			GROUP BY time_segment
			ORDER BY
				CASE time_segment
					WHEN 'Same Day' THEN 1
					WHEN '1 Day' THEN 2
					WHEN '2-7 Days' THEN 3
					WHEN '8-30 Days' THEN 4
					WHEN '31-90 Days' THEN 5
					WHEN '90+ Days' THEN 6
				END
		";

		return self::get_cached( 'time_to_first_purchase_' . md5( $query ), $query );
	}

	/**
	 * Get customer activation funnel.
	 *
	 * @param string $start_date Start date for customer creation filter.
	 * @param string $end_date   End date for customer creation filter.
	 * @return array Funnel stages from signup to subscriber.
	 */
	public static function get_customer_activation_funnel( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				COUNT(DISTINCT c.id) AS total_signups,
				COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN c.id END) AS made_purchase,
				COUNT(DISTINCT CASE WHEN purchase_counts.purchase_count >= 2 THEN c.id END) AS repeat_purchase,
				COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN c.id END) AS became_subscriber,
				ROUND(100.0 * COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN c.id END) / COUNT(DISTINCT c.id), 2) AS purchase_rate,
				ROUND(100.0 * COUNT(DISTINCT CASE WHEN purchase_counts.purchase_count >= 2 THEN c.id END) / NULLIF(COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN c.id END), 0), 2) AS repeat_rate,
				ROUND(100.0 * COUNT(DISTINCT CASE WHEN s.id IS NOT NULL THEN c.id END) / NULLIF(COUNT(DISTINCT CASE WHEN o.id IS NOT NULL THEN c.id END), 0), 2) AS subscription_rate
			FROM {$wpdb->prefix}edd_customers c
			LEFT JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				AND o.status IN ('complete', 'edd_subscription')
			LEFT JOIN {$wpdb->prefix}edd_subscriptions s ON c.id = s.customer_id
				AND s.status != 'pending'
			LEFT JOIN (
				SELECT
					customer_id,
					COUNT(DISTINCT id) AS purchase_count
				FROM {$wpdb->prefix}edd_orders
				WHERE status IN ('complete', 'edd_subscription')
				GROUP BY customer_id
			) AS purchase_counts ON c.id = purchase_counts.customer_id
			WHERE c.date_created IS NOT NULL
			{$where}
		";

		$result = self::get_cached( 'activation_funnel_' . md5( $query ), $query );

		if ( empty( $result ) || ! isset( $result[0] ) ) {
			return array(
				'total_signups'      => 0,
				'made_purchase'      => 0,
				'repeat_purchase'    => 0,
				'became_subscriber'  => 0,
				'purchase_rate'      => 0,
				'repeat_rate'        => 0,
				'subscription_rate'  => 0,
			);
		}

		return $result[0];
	}

	// =========================
	// GEOGRAPHIC ANALYSIS
	// =========================

	/**
	 * Get customers by country.
	 *
	 * @param string $start_date Start date for customer creation filter.
	 * @param string $end_date   End date for customer creation filter.
	 * @param int    $limit      Number of results to return (default: 20).
	 * @return array Customer distribution by country.
	 */
	public static function get_customers_by_country( $start_date = null, $end_date = null, $limit = 20 ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND c.date_created <= %s', $end_date );
		}

		// Note: Adjust based on how EDD stores customer country (could be in order_addresses or customer meta)
		$query = $wpdb->prepare(
			"
			SELECT
				COALESCE(oa.country, 'Unknown') AS country,
				COUNT(DISTINCT c.id) AS customer_count,
				COUNT(DISTINCT o.id) AS total_orders,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS total_revenue
			FROM {$wpdb->prefix}edd_customers c
			LEFT JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
			LEFT JOIN {$wpdb->prefix}edd_order_addresses oa ON o.id = oa.order_id
				AND oa.type = 'billing'
			WHERE c.date_created IS NOT NULL
			{$where}
			GROUP BY country
			ORDER BY customer_count DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( 'customers_by_country_' . md5( $query ), $query );
	}

	/**
	 * Get revenue by region.
	 *
	 * @param string $start_date Start date for order filter.
	 * @param string $end_date   End date for order filter.
	 * @return array Revenue breakdown by geographic region.
	 */
	public static function get_revenue_by_region( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				CASE
					WHEN oa.country IN ('US', 'CA', 'MX') THEN 'North America'
					WHEN oa.country IN ('GB', 'DE', 'FR', 'IT', 'ES', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'DK', 'FI', 'IE', 'PT', 'PL', 'CZ', 'GR') THEN 'Europe'
					WHEN oa.country IN ('AU', 'NZ', 'SG', 'MY', 'TH', 'ID', 'PH', 'VN') THEN 'Asia Pacific'
					WHEN oa.country IN ('BR', 'AR', 'CL', 'CO', 'PE', 'VE', 'EC', 'UY') THEN 'Latin America'
					WHEN oa.country IN ('IN', 'PK', 'BD', 'LK') THEN 'South Asia'
					WHEN oa.country IN ('CN', 'JP', 'KR', 'TW', 'HK') THEN 'East Asia'
					WHEN oa.country IN ('ZA', 'EG', 'KE', 'NG', 'GH', 'TZ', 'UG') THEN 'Africa'
					WHEN oa.country IN ('AE', 'SA', 'IL', 'TR', 'IQ', 'IR', 'JO', 'LB', 'KW', 'QA') THEN 'Middle East'
					ELSE 'Other'
				END AS region,
				COUNT(DISTINCT o.id) AS order_count,
				COUNT(DISTINCT o.customer_id) AS customer_count,
				ROUND(SUM(o.total), 2) AS total_revenue,
				ROUND(AVG(o.total), 2) AS avg_order_value
			FROM {$wpdb->prefix}edd_orders o
			LEFT JOIN {$wpdb->prefix}edd_order_addresses oa ON o.id = oa.order_id
				AND oa.type = 'billing'
			WHERE 1=1
			{$where}
			GROUP BY region
			ORDER BY total_revenue DESC
		";

		return self::get_cached( 'revenue_by_region_' . md5( $query ), $query );
	}

	// =========================
	// PRODUCT PERFORMANCE ANALYTICS
	// =========================

	/**
	 * Get top products by revenue.
	 *
	 * @param string $start_date Start date for order filter.
	 * @param string $end_date   End date for order filter.
	 * @param int    $limit      Number of results to return (default: 20).
	 * @return array Top products with revenue, units sold, and AOV.
	 */
	public static function get_top_products( $start_date = null, $end_date = null, $limit = 20 ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = $wpdb->prepare(
			"
			SELECT
				oi.product_id,
				p.post_title AS product_name,
				COUNT(DISTINCT o.id) AS order_count,
				SUM(oi.quantity) AS units_sold,
				ROUND(SUM(oi.total), 2) AS total_revenue,
				ROUND(AVG(oi.total / oi.quantity), 2) AS avg_price,
				ROUND(SUM(oi.total) / COUNT(DISTINCT o.id), 2) AS aov
			FROM {$wpdb->prefix}edd_order_items oi
			INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
			LEFT JOIN {$wpdb->posts} p ON oi.product_id = p.ID
			WHERE 1=1
			{$where}
			GROUP BY oi.product_id
			ORDER BY total_revenue DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( 'top_products_' . md5( $query ), $query );
	}

	/**
	 * Get product growth trends by month.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @param int    $limit      Number of top products to analyze (default: 10).
	 * @return array Monthly sales trends per product.
	 */
	public static function get_product_growth_trends( $start_date = null, $end_date = null, $limit = 10 ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = $wpdb->prepare(
			"
			SELECT
				DATE_FORMAT(o.date_created, '%%Y-%%m-01') AS date,
				DATE_FORMAT(o.date_created, '%%M %%Y') AS label,
				oi.product_id,
				p.post_title AS product_name,
				SUM(oi.quantity) AS units_sold,
				ROUND(SUM(oi.total), 2) AS revenue,
				COUNT(DISTINCT o.id) AS order_count
			FROM {$wpdb->prefix}edd_order_items oi
			INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
			LEFT JOIN {$wpdb->posts} p ON oi.product_id = p.ID
			WHERE oi.product_id IN (
				SELECT product_id
				FROM {$wpdb->prefix}edd_order_items oi2
				INNER JOIN {$wpdb->prefix}edd_orders o2 ON oi2.order_id = o2.id
				WHERE o2.status IN ('complete', 'edd_subscription')
				GROUP BY product_id
				ORDER BY SUM(oi2.total) DESC
				LIMIT %d
			)
			{$where}
			GROUP BY
				YEAR(o.date_created),
				MONTH(o.date_created),
				oi.product_id
			ORDER BY date, revenue DESC
			",
			$limit
		);

		return self::get_cached( 'product_growth_trends_' . md5( $query ), $query );
	}

	/**
	 * Get product performance matrix (quadrant analysis).
	 *
	 * @param string $start_date Start date for comparison period.
	 * @param string $end_date   End date for comparison period.
	 * @return array Products classified by revenue and growth quadrants.
	 */
	public static function get_product_performance_matrix( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				product_id,
				product_name,
				current_revenue,
				previous_revenue,
				growth_rate,
				units_sold,
				CASE
					WHEN current_revenue >= avg_revenue AND growth_rate >= avg_growth THEN 'Star'
					WHEN current_revenue >= avg_revenue AND growth_rate < avg_growth THEN 'Cash Cow'
					WHEN current_revenue < avg_revenue AND growth_rate >= avg_growth THEN 'Question Mark'
					ELSE 'Dog'
				END AS quadrant
			FROM (
				SELECT
					oi.product_id,
					p.post_title AS product_name,
					ROUND(SUM(CASE
						WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
						THEN oi.total ELSE 0
					END), 2) AS current_revenue,
					ROUND(SUM(CASE
						WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
						AND o.date_created < DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
						THEN oi.total ELSE 0
					END), 2) AS previous_revenue,
					ROUND(
						100.0 * (
							SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN oi.total ELSE 0 END) -
							SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.date_created < DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN oi.total ELSE 0 END)
						) / NULLIF(SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o.date_created < DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN oi.total ELSE 0 END), 0),
						2
					) AS growth_rate,
					SUM(oi.quantity) AS units_sold,
					(SELECT AVG(product_revenue) FROM (
						SELECT SUM(oi2.total) AS product_revenue
						FROM {$wpdb->prefix}edd_order_items oi2
						INNER JOIN {$wpdb->prefix}edd_orders o2 ON oi2.order_id = o2.id
						WHERE o2.status IN ('complete', 'edd_subscription')
						AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
						GROUP BY oi2.product_id
					) AS revenue_avg) AS avg_revenue,
					(SELECT AVG(product_growth) FROM (
						SELECT
							100.0 * (
								SUM(CASE WHEN o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN oi2.total ELSE 0 END) -
								SUM(CASE WHEN o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o2.date_created < DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN oi2.total ELSE 0 END)
							) / NULLIF(SUM(CASE WHEN o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND o2.date_created < DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN oi2.total ELSE 0 END), 0) AS product_growth
						FROM {$wpdb->prefix}edd_order_items oi2
						INNER JOIN {$wpdb->prefix}edd_orders o2 ON oi2.order_id = o2.id
						WHERE o2.status IN ('complete', 'edd_subscription')
						GROUP BY oi2.product_id
					) AS growth_avg) AS avg_growth
				FROM {$wpdb->prefix}edd_order_items oi
				INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
				LEFT JOIN {$wpdb->posts} p ON oi.product_id = p.ID
				WHERE 1=1
				{$where}
				GROUP BY oi.product_id
				HAVING current_revenue > 0
			) AS product_metrics
			ORDER BY current_revenue DESC
		";

		return self::get_cached( 'product_performance_matrix_' . md5( $query ), $query );
	}

	/**
	 * Get bundle performance comparison.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Performance comparison of bundles vs individual products.
	 */
	public static function get_bundle_performance( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		// Note: Adjust based on how EDD Bundles are identified (parent_id or post_type)
		$query = "
			SELECT
				CASE
					WHEN p.post_parent > 0 OR pm.meta_value IS NOT NULL THEN 'Bundle'
					ELSE 'Individual'
				END AS product_type,
				COUNT(DISTINCT oi.product_id) AS product_count,
				COUNT(DISTINCT o.id) AS order_count,
				SUM(oi.quantity) AS units_sold,
				ROUND(SUM(oi.total), 2) AS total_revenue,
				ROUND(AVG(oi.total), 2) AS avg_revenue_per_sale,
				ROUND(SUM(oi.total) / COUNT(DISTINCT o.customer_id), 2) AS revenue_per_customer
			FROM {$wpdb->prefix}edd_order_items oi
			INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
			LEFT JOIN {$wpdb->posts} p ON oi.product_id = p.ID
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				AND pm.meta_key = '_edd_bundled_products'
			WHERE 1=1
			{$where}
			GROUP BY product_type
			ORDER BY total_revenue DESC
		";

		return self::get_cached( 'bundle_performance_' . md5( $query ), $query );
	}

	/**
	 * Get product lifecycle stages.
	 *
	 * @return array Products classified by lifecycle stage (growth/maturity/decline).
	 */
	public static function get_product_lifecycle_stages() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				product_id,
				product_name,
				months_active,
				revenue_trend,
				current_revenue,
				peak_revenue,
				CASE
					WHEN months_active <= 3 AND revenue_trend > 20 THEN 'Introduction'
					WHEN revenue_trend > 10 THEN 'Growth'
					WHEN revenue_trend >= -10 AND revenue_trend <= 10 THEN 'Maturity'
					WHEN revenue_trend < -10 AND revenue_trend >= -30 THEN 'Decline'
					ELSE 'End of Life'
				END AS lifecycle_stage,
				last_sale_date
			FROM (
				SELECT
					oi.product_id,
					p.post_title AS product_name,
					TIMESTAMPDIFF(MONTH, MIN(o.date_created), CURDATE()) AS months_active,
					ROUND(
						100.0 * (
							SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN oi.total ELSE 0 END) -
							SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND o.date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN oi.total ELSE 0 END)
						) / NULLIF(SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND o.date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN oi.total ELSE 0 END), 0),
						2
					) AS revenue_trend,
					ROUND(SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN oi.total ELSE 0 END), 2) AS current_revenue,
					ROUND(MAX(monthly_revenue.revenue), 2) AS peak_revenue,
					MAX(o.date_created) AS last_sale_date
				FROM {$wpdb->prefix}edd_order_items oi
				INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
				LEFT JOIN {$wpdb->posts} p ON oi.product_id = p.ID
				LEFT JOIN (
					SELECT
						oi2.product_id,
						DATE_FORMAT(o2.date_created, '%Y-%m') AS month,
						SUM(oi2.total) AS revenue
					FROM {$wpdb->prefix}edd_order_items oi2
					INNER JOIN {$wpdb->prefix}edd_orders o2 ON oi2.order_id = o2.id
					WHERE o2.status IN ('complete', 'edd_subscription')
					GROUP BY oi2.product_id, DATE_FORMAT(o2.date_created, '%Y-%m')
				) AS monthly_revenue ON oi.product_id = monthly_revenue.product_id
				WHERE o.status IN ('complete', 'edd_subscription')
				GROUP BY oi.product_id
				HAVING months_active > 0
			) AS product_metrics
			ORDER BY
				CASE lifecycle_stage
					WHEN 'Introduction' THEN 1
					WHEN 'Growth' THEN 2
					WHEN 'Maturity' THEN 3
					WHEN 'Decline' THEN 4
					WHEN 'End of Life' THEN 5
				END,
				current_revenue DESC
		";

		return self::get_cached( 'product_lifecycle_stages', $query );
	}

	// =========================
    // REVENUE ANALYTICS
    // =========================

    /**
     * Get detailed revenue breakdown by type.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Revenue segmented by new/recurring/upgrade/downgrade.
	 */
	public static function get_revenue_breakdown( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(o.date_created, '%Y-%m-01') AS date,
				DATE_FORMAT(o.date_created, '%M %Y') AS label,
				ROUND(SUM(CASE WHEN o.type = 'sale' AND is_first_purchase = 1 THEN o.total ELSE 0 END), 2) AS new_customer_revenue,
				ROUND(SUM(CASE WHEN o.type = 'sale' AND is_first_purchase = 0 THEN o.total ELSE 0 END), 2) AS existing_customer_revenue,
				ROUND(SUM(CASE WHEN o.type = 'renewal' THEN o.total ELSE 0 END), 2) AS renewal_revenue,
				ROUND(SUM(CASE WHEN o.type = 'sale' AND upgrade_indicator = 1 THEN o.total ELSE 0 END), 2) AS upgrade_revenue,
				ROUND(SUM(o.total), 2) AS total_revenue
			FROM (
				SELECT
					o.*,
					CASE
						WHEN (
							SELECT COUNT(*)
							FROM {$wpdb->prefix}edd_orders o2
							WHERE o2.customer_id = o.customer_id
							AND o2.status IN ('complete', 'edd_subscription')
							AND o2.date_created < o.date_created
						) = 0 THEN 1
						ELSE 0
					END AS is_first_purchase,
					CASE
						WHEN o.total > (
							SELECT AVG(o3.total)
							FROM {$wpdb->prefix}edd_orders o3
							WHERE o3.customer_id = o.customer_id
							AND o3.status IN ('complete', 'edd_subscription')
							AND o3.date_created < o.date_created
						) THEN 1
						ELSE 0
					END AS upgrade_indicator
				FROM {$wpdb->prefix}edd_orders o
				WHERE o.status IN ('complete', 'edd_subscription')
				{$where}
			) AS o
			GROUP BY
				YEAR(o.date_created),
				MONTH(o.date_created)
			ORDER BY date
		";

		$results = self::get_cached( 'revenue_breakdown_' . md5( $query ), $query );
		// Transform to waterfall format expected by frontend
		$waterfall_data = array();
		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				$waterfall_data[] = array(
					'source' => 'monthly',
					'revenue' => floatval( $row['total_revenue'] ?? 0 ),
					'date' => $row['date'] ?? '',
					'label' => $row['label'] ?? '',
				);
			}
		}
		return array(
			'data' => $waterfall_data,
		);
	}

	/**
	 * Get revenue concentration (80/20 analysis).
	 *
	 * @return array Distribution showing revenue concentration by customer/product segments.
	 */
	public static function get_revenue_concentration() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				'Top 10% Customers' AS segment,
				COUNT(*) AS count,
				ROUND(SUM(total_spent), 2) AS revenue,
				ROUND(100.0 * SUM(total_spent) / (SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription')), 2) AS revenue_percentage
			FROM (
				SELECT
					c.id,
					SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) AS total_spent
				FROM {$wpdb->prefix}edd_customers c
				INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				GROUP BY c.id
				ORDER BY total_spent DESC
				LIMIT (SELECT FLOOR(COUNT(DISTINCT id) * 0.1) FROM {$wpdb->prefix}edd_customers)
			) AS top_customers

			UNION ALL

			SELECT
				'Top 20% Customers' AS segment,
				COUNT(*) AS count,
				ROUND(SUM(total_spent), 2) AS revenue,
				ROUND(100.0 * SUM(total_spent) / (SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription')), 2) AS revenue_percentage
			FROM (
				SELECT
					c.id,
					SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) AS total_spent
				FROM {$wpdb->prefix}edd_customers c
				INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				GROUP BY c.id
				ORDER BY total_spent DESC
				LIMIT (SELECT FLOOR(COUNT(DISTINCT id) * 0.2) FROM {$wpdb->prefix}edd_customers)
			) AS top_20_customers

			UNION ALL

			SELECT
				'Top 10% Products' AS segment,
				COUNT(*) AS count,
				ROUND(SUM(product_revenue), 2) AS revenue,
				ROUND(100.0 * SUM(product_revenue) / (SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription')), 2) AS revenue_percentage
			FROM (
				SELECT
					oi.product_id,
					SUM(oi.total) AS product_revenue
				FROM {$wpdb->prefix}edd_order_items oi
				INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
				WHERE o.status IN ('complete', 'edd_subscription')
				GROUP BY oi.product_id
				ORDER BY product_revenue DESC
				LIMIT (SELECT FLOOR(COUNT(DISTINCT product_id) * 0.1) FROM {$wpdb->prefix}edd_order_items)
			) AS top_products
		";

        $results = self::get_cached( 'revenue_concentration', $query );
		// Add cumulative percentage calculation
		$cumulative = 0;
		if ( is_array( $results ) ) {
			foreach ( $results as &$row ) {
				$cumulative += floatval( $row['revenue_percentage'] ?? 0 );
				$row['cumulative_percentage'] = round( $cumulative, 2 );
			}
		}
        return array(
			'data' => $results ? $results : array(),
		);
    }

    /**
     * Get average revenue per customer by month.
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
     * @return array Monthly average revenue per customer.
     */
    public static function get_average_revenue_per_customer( $start_date = null, $end_date = null ) {
        $wpdb = self::get_db();

        $where = " AND o.status IN ('complete', 'edd_subscription')";
        if ( $start_date ) {
            $where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
        }
        if ( $end_date ) {
            $where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
        }

        $query = "
            SELECT
                DATE_FORMAT(o.date_created, '%Y-%m-01') AS date,
                DATE_FORMAT(o.date_created, '%M %Y') AS label,
                ROUND(SUM(o.total) / NULLIF(COUNT(DISTINCT o.customer_id), 0), 2) AS avg_revenue_per_customer
            FROM {$wpdb->prefix}edd_orders o
            WHERE 1=1
            {$where}
            GROUP BY YEAR(o.date_created), MONTH(o.date_created)
            ORDER BY date
        ";

        return self::get_cached( 'avg_rev_per_customer_' . md5( $query ), $query );
    }

    /**
     * Get payment method performance.
     *
     * @param string $start_date Start date.
     * @param string $end_date   End date.
	 * @return array Success rates and revenue by payment gateway.
	 */
	public static function get_payment_method_performance( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				COALESCE(o.gateway, 'Unknown') AS payment_method,
				COUNT(*) AS total_attempts,
				COUNT(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN 1 END) AS successful_payments,
				COUNT(CASE WHEN o.status = 'failed' THEN 1 END) AS failed_payments,
				ROUND(100.0 * COUNT(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN 1 END) / COUNT(*), 2) AS success_rate,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS total_revenue,
				ROUND(AVG(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE NULL END), 2) AS avg_transaction_value
			FROM {$wpdb->prefix}edd_orders o
			WHERE 1=1
			{$where}
			GROUP BY payment_method
			ORDER BY total_revenue DESC
		";

		return self::get_cached( 'payment_method_performance_' . md5( $query ), $query );
	}

	/**
	 * Get failed payment recovery rates.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Recovery metrics for failed payments.
	 */
	public static function get_failed_payment_recovery( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = '';
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND failed_orders.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND failed_orders.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				DATE_FORMAT(failed_orders.date_created, '%Y-%m-01') AS date,
				DATE_FORMAT(failed_orders.date_created, '%M %Y') AS label,
				COUNT(DISTINCT failed_orders.id) AS failed_count,
				COUNT(DISTINCT recovery.id) AS recovered_count,
				ROUND(100.0 * COUNT(DISTINCT recovery.id) / COUNT(DISTINCT failed_orders.id), 2) AS recovery_rate,
				ROUND(SUM(CASE WHEN recovery.id IS NOT NULL THEN recovery.total ELSE 0 END), 2) AS recovered_revenue,
				ROUND(AVG(DATEDIFF(recovery.date_created, failed_orders.date_created)), 1) AS avg_days_to_recovery
			FROM {$wpdb->prefix}edd_orders failed_orders
			LEFT JOIN {$wpdb->prefix}edd_orders recovery
				ON failed_orders.customer_id = recovery.customer_id
				AND recovery.status IN ('complete', 'edd_subscription')
				AND recovery.date_created > failed_orders.date_created
				AND recovery.date_created <= DATE_ADD(failed_orders.date_created, INTERVAL 30 DAY)
			WHERE failed_orders.status = 'failed'
			{$where}
			GROUP BY
				YEAR(failed_orders.date_created),
				MONTH(failed_orders.date_created)
			ORDER BY date
		";

		$results = self::get_cached( 'failed_payment_recovery_' . md5( $query ), $query );
		return array(
			'data' => $results ? $results : array(),
		);
	}

	/**
	 * Get revenue velocity metrics.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Speed of revenue growth and acceleration metrics.
	 */
	public static function get_revenue_velocity( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		$query = "
			SELECT
				date,
				label,
				monthly_revenue,
				LAG(monthly_revenue) OVER (ORDER BY date) AS previous_month_revenue,
				ROUND(monthly_revenue - LAG(monthly_revenue) OVER (ORDER BY date), 2) AS revenue_change,
				ROUND(
					100.0 * (monthly_revenue - LAG(monthly_revenue) OVER (ORDER BY date)) /
					NULLIF(LAG(monthly_revenue) OVER (ORDER BY date), 0),
					2
				) AS growth_rate,
				ROUND(
					(monthly_revenue - LAG(monthly_revenue) OVER (ORDER BY date)) -
					(LAG(monthly_revenue) OVER (ORDER BY date) - LAG(monthly_revenue, 2) OVER (ORDER BY date)),
					2
				) AS acceleration
			FROM (
				SELECT
					DATE_FORMAT(o.date_created, '%Y-%m-01') AS date,
					DATE_FORMAT(o.date_created, '%M %Y') AS label,
					ROUND(SUM(o.total), 2) AS monthly_revenue
				FROM {$wpdb->prefix}edd_orders o
				WHERE 1=1
				{$where}
				GROUP BY
					YEAR(o.date_created),
					MONTH(o.date_created)
			) AS monthly_data
			ORDER BY date
		";

		return self::get_cached( 'revenue_velocity_' . md5( $query ), $query );
	}

	// =========================
	// FINANCIAL METRICS
	// =========================

	/**
	 * Get cash flow projection.
	 *
	 * @param int $days Number of days to project (default: 90).
	 * @return array Projected cash flow for next 30/60/90 days.
	 */
	public static function get_cash_flow_projection( $days = 90 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				'Confirmed Revenue' AS category,
				ROUND(SUM(CASE WHEN s.expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN s.recurring_amount ELSE 0 END), 2) AS next_30_days,
				ROUND(SUM(CASE WHEN s.expiration <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN s.recurring_amount ELSE 0 END), 2) AS next_60_days,
				ROUND(SUM(CASE WHEN s.expiration <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN s.recurring_amount ELSE 0 END), 2) AS next_90_days
			FROM {$wpdb->prefix}edd_subscriptions s
			WHERE s.status = 'active'
			AND s.expiration >= CURDATE()
			AND s.expiration <= DATE_ADD(CURDATE(), INTERVAL %d DAY)

			UNION ALL

			SELECT
				'Projected New Sales' AS category,
				ROUND(AVG(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN daily_revenue ELSE NULL END) * 30, 2) AS next_30_days,
				ROUND(AVG(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) THEN daily_revenue ELSE NULL END) * 60, 2) AS next_60_days,
				ROUND(AVG(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN daily_revenue ELSE NULL END) * 90, 2) AS next_90_days
			FROM (
				SELECT
					DATE(date_created) AS sale_date,
					SUM(total) AS daily_revenue
				FROM {$wpdb->prefix}edd_orders o
				WHERE status IN ('complete', 'edd_subscription')
				AND type = 'sale'
				GROUP BY DATE(date_created)
			) AS o

			UNION ALL

			SELECT
				'At-Risk Revenue' AS category,
				ROUND(SUM(CASE
					WHEN s.expiration <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
					AND (
						SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o
						WHERE o.customer_id = s.customer_id
						AND o.status = 'failed'
						AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
					) > 0
					THEN s.recurring_amount ELSE 0
				END), 2) AS next_30_days,
				ROUND(SUM(CASE
					WHEN s.expiration <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
					AND (
						SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o
						WHERE o.customer_id = s.customer_id
						AND o.status = 'failed'
						AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
					) > 0
					THEN s.recurring_amount ELSE 0
				END), 2) AS next_60_days,
				ROUND(SUM(CASE
					WHEN s.expiration <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
					AND (
						SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o
						WHERE o.customer_id = s.customer_id
						AND o.status = 'failed'
						AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
					) > 0
					THEN s.recurring_amount ELSE 0
				END), 2) AS next_90_days
			FROM {$wpdb->prefix}edd_subscriptions s
			WHERE s.status = 'active'
			",
			$days
		);

		return self::get_cached( "cash_flow_projection_{$days}", $query );
	}

	/**
	 * Get profitability by segment.
	 *
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array Profit margins by product/customer segment.
	 */
	public static function get_profitability_by_segment( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();

		$where = " AND o.status IN ('complete', 'edd_subscription')";
		if ( $start_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created >= %s', $start_date );
		}
		if ( $end_date ) {
			$where .= $wpdb->prepare( ' AND o.date_created <= %s', $end_date );
		}

		// Note: This assumes product cost is stored in postmeta as '_edd_product_cost'
		$query = "
			SELECT
				'By Product Type' AS segment_type,
				CASE
					WHEN pm.meta_value IS NOT NULL THEN 'Bundle'
					ELSE 'Individual'
				END AS segment_name,
				ROUND(SUM(oi.total), 2) AS revenue,
				ROUND(SUM(oi.quantity * COALESCE(cost.meta_value, 0)), 2) AS estimated_cost,
				ROUND(SUM(oi.total) - SUM(oi.quantity * COALESCE(cost.meta_value, 0)), 2) AS gross_profit,
				ROUND(100.0 * (SUM(oi.total) - SUM(oi.quantity * COALESCE(cost.meta_value, 0))) / NULLIF(SUM(oi.total), 0), 2) AS profit_margin
			FROM {$wpdb->prefix}edd_order_items oi
			INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
			LEFT JOIN {$wpdb->posts} p ON oi.product_id = p.ID
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_edd_bundled_products'
			LEFT JOIN {$wpdb->postmeta} cost ON p.ID = cost.post_id AND cost.meta_key = '_edd_product_cost'
			WHERE 1=1
			{$where}
			GROUP BY segment_name

			UNION ALL

			SELECT
				'By Customer Segment' AS segment_type,
				rfm_segment AS segment_name,
				ROUND(SUM(segment_revenue), 2) AS revenue,
				0 AS estimated_cost,
				ROUND(SUM(segment_revenue), 2) AS gross_profit,
				100.0 AS profit_margin
			FROM (
				SELECT
					o.customer_id,
					SUM(o.total) AS segment_revenue,
					CASE
						WHEN (
							SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2
							WHERE o2.customer_id = o.customer_id
							AND o2.status IN ('complete', 'edd_subscription')
						) >= 5 THEN 'Loyal'
						WHEN (
							SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2
							WHERE o2.customer_id = o.customer_id
							AND o2.status IN ('complete', 'edd_subscription')
						) >= 2 THEN 'Repeat'
						ELSE 'One-Time'
					END AS rfm_segment
				FROM {$wpdb->prefix}edd_orders o
				WHERE o.status IN ('complete', 'edd_subscription')
				{$where}
				GROUP BY o.customer_id
			) AS customer_segments
			GROUP BY rfm_segment

			ORDER BY segment_type, revenue DESC
		";

		return self::get_cached( 'profitability_by_segment_' . md5( $query ), $query );
	}

	/**
	 * Get LTV:CAC ratio.
	 *
	 * @return array Customer acquisition cost vs lifetime value ratio.
	 */
	public static function get_ltv_cac_ratio() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				ROUND(AVG(customer_ltv), 2) AS avg_ltv,
				ROUND(AVG(customer_ltv) / NULLIF((
					SELECT SUM(o.total) FROM {$wpdb->prefix}edd_orders o
					WHERE o.status IN ('complete', 'edd_subscription')
					AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				) / NULLIF((
					SELECT COUNT(DISTINCT id) FROM {$wpdb->prefix}edd_customers
					WHERE date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				), 0), 0), 2) AS ltv_cac_ratio,
				(
					SELECT SUM(o.total) FROM {$wpdb->prefix}edd_orders o
					WHERE o.status IN ('complete', 'edd_subscription')
					AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				) / NULLIF((
					SELECT COUNT(DISTINCT id) FROM {$wpdb->prefix}edd_customers
					WHERE date_created >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				), 0) AS estimated_cac,
				COUNT(*) AS customer_count
			FROM (
				SELECT
					c.id,
					SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END) AS customer_ltv
				FROM {$wpdb->prefix}edd_customers c
				INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
				GROUP BY c.id
			) AS customer_metrics
		";

		return self::get_cached( 'ltv_cac_ratio', $query );
	}

	/**
	 * Get revenue run rate.
	 *
	 * @return array Annual and monthly run rate calculations.
	 */
	public static function get_revenue_run_rate() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				ROUND(SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN o.total ELSE 0 END), 2) AS last_month_revenue,
				ROUND(SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) THEN o.total ELSE 0 END) * 12, 2) AS annual_run_rate,
				ROUND(SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN o.total ELSE 0 END) / 3, 2) AS avg_monthly_revenue_3mo,
				ROUND((SUM(CASE WHEN o.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN o.total ELSE 0 END) / 3) * 12, 2) AS annual_run_rate_3mo,
				ROUND(
					(SELECT SUM(recurring_amount * 12) FROM {$wpdb->prefix}edd_subscriptions WHERE status = 'active'),
					2
				) AS arr_from_subscriptions,
				ROUND(
					(SELECT SUM(recurring_amount) FROM {$wpdb->prefix}edd_subscriptions WHERE status = 'active'),
					2
				) AS mrr_from_subscriptions
			FROM {$wpdb->prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
		";

		return self::get_cached( 'revenue_run_rate', $query );
	}

	/**
	 * Get financial health indicators.
	 *
	 * @return array Key financial health metrics and benchmarks.
	 */
	public static function get_financial_health_indicators() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				'Revenue Growth' AS metric,
				ROUND(
					100.0 * (
						(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) -
						(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
					) / NULLIF((SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
					2
				) AS value,
				'%' AS unit,
				CASE
					WHEN ROUND(
						100.0 * (
							(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) -
							(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
						) / NULLIF((SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) >= 10 THEN 'Excellent'
					WHEN ROUND(
						100.0 * (
							(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) -
							(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
						) / NULLIF((SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) >= 5 THEN 'Good'
					WHEN ROUND(
						100.0 * (
							(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) -
							(SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
						) / NULLIF((SELECT SUM(total) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 MONTH) AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) >= 0 THEN 'Fair'
					ELSE 'Poor'
				END AS health_status

			UNION ALL

			SELECT
				'Churn Rate' AS metric,
				ROUND(
					100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE status IN ('cancelled', 'expired') AND expiration >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
					NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
					2
				) AS value,
				'%' AS unit,
				CASE
					WHEN ROUND(
						100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE status IN ('cancelled', 'expired') AND expiration >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
						NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) <= 3 THEN 'Excellent'
					WHEN ROUND(
						100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE status IN ('cancelled', 'expired') AND expiration >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
						NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) <= 5 THEN 'Good'
					WHEN ROUND(
						100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE status IN ('cancelled', 'expired') AND expiration >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
						NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions WHERE created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) <= 7 THEN 'Fair'
					ELSE 'Poor'
				END AS health_status

			UNION ALL

			SELECT
				'Customer Retention' AS metric,
				ROUND(
					100.0 * (SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND customer_id IN (SELECT DISTINCT customer_id FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) /
					NULLIF((SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
					2
				) AS value,
				'%' AS unit,
				CASE
					WHEN ROUND(
						100.0 * (SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND customer_id IN (SELECT DISTINCT customer_id FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) /
						NULLIF((SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) >= 80 THEN 'Excellent'
					WHEN ROUND(
						100.0 * (SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND customer_id IN (SELECT DISTINCT customer_id FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) /
						NULLIF((SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) >= 70 THEN 'Good'
					WHEN ROUND(
						100.0 * (SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND customer_id IN (SELECT DISTINCT customer_id FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) /
						NULLIF((SELECT COUNT(DISTINCT customer_id) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription') AND date_created < DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) >= 60 THEN 'Fair'
					ELSE 'Poor'
				END AS health_status

			UNION ALL

			SELECT
				'Refund Rate' AS metric,
				ROUND(
					100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status = 'refunded' AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
					NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription', 'refunded') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
					2
				) AS value,
				'%' AS unit,
				CASE
					WHEN ROUND(
						100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status = 'refunded' AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
						NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription', 'refunded') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) <= 2 THEN 'Excellent'
					WHEN ROUND(
						100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status = 'refunded' AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
						NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription', 'refunded') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) <= 5 THEN 'Good'
					WHEN ROUND(
						100.0 * (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status = 'refunded' AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) /
						NULLIF((SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders WHERE status IN ('complete', 'edd_subscription', 'refunded') AND date_created >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 0),
						2
					) <= 8 THEN 'Fair'
					ELSE 'Poor'
				END AS health_status
		";

		return self::get_cached( 'financial_health_indicators', $query );
	}

	// =========================
	// PREDICTIVE ANALYTICS
	// =========================

	/**
	 * Get churn prediction scores.
	 *
	 * @param int $limit Number of results to return (default: 100).
	 * @return array Customers with churn likelihood scores.
	 */
	public static function get_churn_prediction_scores( $limit = 100 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				c.id AS customer_id,
				c.email,
				c.name,
				DATEDIFF(CURDATE(), MAX(o.date_created)) AS days_since_last_order,
				COUNT(DISTINCT o.id) AS total_orders,
				ROUND(SUM(CASE WHEN o.status IN ('complete', 'edd_subscription') THEN o.total ELSE 0 END), 2) AS lifetime_value,
				(SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s WHERE s.customer_id = c.id AND s.status = 'active') AS active_subscriptions,
				(SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2 WHERE o2.customer_id = c.id AND o2.status = 'failed' AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) AS recent_failed_payments,
				CASE
					WHEN (
						DATEDIFF(CURDATE(), MAX(o.date_created)) > 180
						OR (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2 WHERE o2.customer_id = c.id AND o2.status = 'failed' AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) >= 2
						OR (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s WHERE s.customer_id = c.id AND s.status IN ('cancelled', 'expired') AND s.expiration >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) > 0
					) THEN 'High'
					WHEN (
						DATEDIFF(CURDATE(), MAX(o.date_created)) > 90
						OR (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2 WHERE o2.customer_id = c.id AND o2.status = 'failed' AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) >= 1
					) THEN 'Medium'
					ELSE 'Low'
				END AS churn_risk,
				CASE
					WHEN (
						DATEDIFF(CURDATE(), MAX(o.date_created)) > 180
						OR (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2 WHERE o2.customer_id = c.id AND o2.status = 'failed' AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) >= 2
						OR (SELECT COUNT(*) FROM {$wpdb->prefix}edd_subscriptions s WHERE s.customer_id = c.id AND s.status IN ('cancelled', 'expired') AND s.expiration >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) > 0
					) THEN 85
					WHEN (
						DATEDIFF(CURDATE(), MAX(o.date_created)) > 90
						OR (SELECT COUNT(*) FROM {$wpdb->prefix}edd_orders o2 WHERE o2.customer_id = c.id AND o2.status = 'failed' AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)) >= 1
					) THEN 55
					ELSE 20
				END AS churn_score
			FROM {$wpdb->prefix}edd_customers c
			INNER JOIN {$wpdb->prefix}edd_orders o ON c.id = o.customer_id
			WHERE o.status IN ('complete', 'edd_subscription', 'failed')
			GROUP BY c.id
			HAVING total_orders > 0
			ORDER BY churn_score DESC, lifetime_value DESC
			LIMIT %d
			",
			$limit
		);

		return self::get_cached( "churn_prediction_scores_{$limit}", $query );
	}

	/**
	 * Get revenue forecast based on historical trends.
	 *
	 * @param int $months Number of months to forecast (default: 6).
	 * @return array Projected revenue for upcoming months.
	 */
	public static function get_revenue_forecast( $months = 6 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				forecast_date,
				forecast_label,
				ROUND(
					avg_revenue * (1 + (avg_growth_rate / 100)),
					2
				) AS projected_revenue,
				ROUND(
					avg_revenue * (1 + ((avg_growth_rate - stddev_growth_rate) / 100)),
					2
				) AS conservative_estimate,
				ROUND(
					avg_revenue * (1 + ((avg_growth_rate + stddev_growth_rate) / 100)),
					2
				) AS optimistic_estimate,
				avg_growth_rate AS growth_rate,
				confidence_level
			FROM (
				SELECT
					DATE_ADD(DATE_FORMAT(CURDATE(), '%%Y-%%m-01'), INTERVAL n MONTH) AS forecast_date,
					DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL n MONTH), '%%M %%Y') AS forecast_label,
					AVG(monthly_revenue) AS avg_revenue,
					AVG(growth_rate) AS avg_growth_rate,
					STDDEV(growth_rate) AS stddev_growth_rate,
					CASE
						WHEN COUNT(*) >= 12 THEN 'High'
						WHEN COUNT(*) >= 6 THEN 'Medium'
						ELSE 'Low'
					END AS confidence_level,
					n
				FROM (
					SELECT
						DATE_FORMAT(o.date_created, '%%Y-%%m-01') AS month,
						SUM(o.total) AS monthly_revenue,
						100.0 * (SUM(o.total) - LAG(SUM(o.total)) OVER (ORDER BY DATE_FORMAT(o.date_created, '%%Y-%%m-01'))) /
						NULLIF(LAG(SUM(o.total)) OVER (ORDER BY DATE_FORMAT(o.date_created, '%%Y-%%m-01')), 0) AS growth_rate
					FROM {$wpdb->prefix}edd_orders o
					WHERE o.status IN ('complete', 'edd_subscription')
					AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
					GROUP BY DATE_FORMAT(o.date_created, '%%Y-%%m-01')
				) AS monthly_data
				CROSS JOIN (
					SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
				) AS forecast_months
				WHERE n <= %d
				GROUP BY n
			) AS forecast_data
			ORDER BY forecast_date
			",
			$months
		);

		return self::get_cached( "revenue_forecast_{$months}", $query );
	}

	/**
	 * Get demand forecast by product.
	 *
	 * @param int $days Number of days to forecast (default: 30).
	 * @param int $limit Number of top products to forecast (default: 10).
	 * @return array Predicted demand for top products.
	 */
	public static function get_demand_forecast( $days = 30, $limit = 10 ) {
		$wpdb = self::get_db();

		$query = $wpdb->prepare(
			"
			SELECT
				product_id,
				product_name,
				ROUND(avg_daily_sales * %d, 0) AS forecasted_units,
				ROUND(avg_daily_revenue * %d, 2) AS forecasted_revenue,
				avg_daily_sales,
				avg_daily_revenue,
				trend_direction,
				CASE
					WHEN data_points >= 90 THEN 'High'
					WHEN data_points >= 30 THEN 'Medium'
					ELSE 'Low'
				END AS forecast_confidence
			FROM (
				SELECT
					oi.product_id,
					p.post_title AS product_name,
					AVG(daily_units) AS avg_daily_sales,
					AVG(daily_revenue) AS avg_daily_revenue,
					COUNT(DISTINCT sale_date) AS data_points,
					CASE
						WHEN AVG(CASE WHEN sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN daily_units END) >
							 AVG(CASE WHEN sale_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND sale_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN daily_units END)
						THEN 'Increasing'
						WHEN AVG(CASE WHEN sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN daily_units END) <
							 AVG(CASE WHEN sale_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND sale_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN daily_units END)
						THEN 'Decreasing'
						ELSE 'Stable'
					END AS trend_direction
				FROM (
					SELECT
						oi.product_id,
						DATE(o.date_created) AS sale_date,
						SUM(oi.quantity) AS daily_units,
						SUM(oi.total) AS daily_revenue
					FROM {$wpdb->prefix}edd_order_items oi
					INNER JOIN {$wpdb->prefix}edd_orders o ON oi.order_id = o.id
					WHERE o.status IN ('complete', 'edd_subscription')
					AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
					GROUP BY oi.product_id, DATE(o.date_created)
				) AS daily_sales
				INNER JOIN (
					SELECT oi2.product_id, SUM(oi2.total) AS total_revenue
					FROM {$wpdb->prefix}edd_order_items oi2
					INNER JOIN {$wpdb->prefix}edd_orders o2 ON oi2.order_id = o2.id
					WHERE o2.status IN ('complete', 'edd_subscription')
					AND o2.date_created >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
					GROUP BY oi2.product_id
					ORDER BY total_revenue DESC
					LIMIT %d
				) AS top_products ON daily_sales.product_id = top_products.product_id
				LEFT JOIN {$wpdb->posts} p ON daily_sales.product_id = p.ID
				GROUP BY product_id
			) AS product_forecasts
			ORDER BY forecasted_revenue DESC
			",
			$days,
			$days,
			$limit
		);

		return self::get_cached( "demand_forecast_{$days}_{$limit}", $query );
	}

	/**
	 * Get seasonal patterns analysis.
	 *
	 * @return array Seasonal trends and patterns in revenue/sales.
	 */
	public static function get_seasonal_patterns() {
		$wpdb = self::get_db();

		$query = "
			SELECT
				month_number,
				month_name,
				ROUND(AVG(monthly_revenue), 2) AS avg_revenue,
				ROUND(AVG(order_count), 0) AS avg_orders,
				ROUND(AVG(customer_count), 0) AS avg_customers,
				ROUND(STDDEV(monthly_revenue), 2) AS revenue_volatility,
				ROUND(
					100.0 * (AVG(monthly_revenue) - overall_avg.avg_all_months) / NULLIF(overall_avg.avg_all_months, 0),
					2
				) AS deviation_from_average,
				CASE
					WHEN AVG(monthly_revenue) >= overall_avg.avg_all_months * 1.2 THEN 'Peak Season'
					WHEN AVG(monthly_revenue) >= overall_avg.avg_all_months * 1.1 THEN 'High Season'
					WHEN AVG(monthly_revenue) >= overall_avg.avg_all_months * 0.9 THEN 'Normal Season'
					WHEN AVG(monthly_revenue) >= overall_avg.avg_all_months * 0.8 THEN 'Low Season'
					ELSE 'Off Season'
				END AS seasonality_classification
			FROM (
				SELECT
					MONTH(o.date_created) AS month_number,
					DATE_FORMAT(o.date_created, '%M') AS month_name,
					YEAR(o.date_created) AS year,
					SUM(o.total) AS monthly_revenue,
					COUNT(DISTINCT o.id) AS order_count,
					COUNT(DISTINCT o.customer_id) AS customer_count
				FROM {$wpdb->prefix}edd_orders o
				WHERE o.status IN ('complete', 'edd_subscription')
				AND o.date_created >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
				GROUP BY YEAR(o.date_created), MONTH(o.date_created)
			) AS monthly_metrics
			CROSS JOIN (
				SELECT AVG(monthly_total) AS avg_all_months
				FROM (
					SELECT SUM(total) AS monthly_total
					FROM {$wpdb->prefix}edd_orders
					WHERE status IN ('complete', 'edd_subscription')
					AND date_created >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
					GROUP BY YEAR(date_created), MONTH(date_created)
				) AS all_months
			) AS overall_avg
			GROUP BY month_number, month_name
			ORDER BY month_number
		";

		return self::get_cached( 'seasonal_patterns', $query );
	}

	/**
	 * Get executive summary data.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Summary metrics.
	 */
	public static function get_executive_summary( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		// Build date filter
		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		// Get previous period for comparison
		$prev_start = $start_date ? date( 'Y-m-d', strtotime( $start_date . ' -1 month' ) ) : null;
		$prev_end = $end_date ? date( 'Y-m-d', strtotime( $end_date . ' -1 month' ) ) : null;
		$prev_date_filter = '';
		if ( $prev_start ) {
			$prev_date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $prev_start );
		}
		if ( $prev_end ) {
			$prev_date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $prev_end . ' 23:59:59' );
		}

		// Current period metrics
		$current = $wpdb->get_row(
			"
			SELECT
				COUNT(DISTINCT o.id) AS total_orders,
				COUNT(DISTINCT o.customer_id) AS new_customers,
				SUM(o.total) AS total_revenue,
				AVG(o.total) AS avg_order_value
			FROM {$prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
			{$date_filter}
			",
			ARRAY_A
		);

		// Previous period metrics
		$previous = $wpdb->get_row(
			"
			SELECT
				COUNT(DISTINCT o.id) AS total_orders,
				COUNT(DISTINCT o.customer_id) AS new_customers,
				SUM(o.total) AS total_revenue
			FROM {$prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
			{$prev_date_filter}
			",
			ARRAY_A
		);

		$current_revenue = floatval( $current->total_revenue ?? 0 );
		$prev_revenue = floatval( $previous->total_revenue ?? 0 );
		$revenue_change = $prev_revenue > 0 ? ( ( $current_revenue - $prev_revenue ) / $prev_revenue ) * 100 : 0;

		$current_customers = intval( $current->new_customers ?? 0 );
		$prev_customers = intval( $previous->new_customers ?? 0 );
		$customer_change = $prev_customers > 0 ? ( ( $current_customers - $prev_customers ) / $prev_customers ) * 100 : 0;

		return array(
			'data' => array(
			'total_revenue'    => $current_revenue,
			'total_orders'     => intval( $current->total_orders ?? 0 ),
			'new_customers'    => $current_customers,
			'avg_order_value'  => floatval( $current->avg_order_value ?? 0 ),
			'revenue_change'   => $revenue_change,
			'customer_change'  => $customer_change,
			),
		);
	}

	/**
	 * Get revenue overview with daily breakdown.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Revenue overview data.
	 */
	public static function get_revenue_overview( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		$daily_revenue = $wpdb->get_results(
			"
			SELECT
				DATE(o.date_created) AS date,
				SUM(o.total) AS revenue,
				COUNT(DISTINCT o.id) AS orders,
				COUNT(DISTINCT o.customer_id) AS customers
			FROM {$prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
			{$date_filter}
			GROUP BY DATE(o.date_created)
			ORDER BY date ASC
			",
			ARRAY_A
		);

		return array(
			'data' => array(
			'daily_revenue' => $daily_revenue,
			),
		);
	}

	/**
	 * Get MRR summary.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array MRR summary data.
	 */
	public static function get_mrr_summary( $start_date = null, $end_date = null ) {
		$current_mrr = self::get_current_mrr_breakdown();
		$mrr_by_month = self::get_mrr_by_month( $start_date, $end_date );

		// Calculate growth rate
		$mrr_growth = 0;
		if ( count( $mrr_by_month ) >= 2 ) {
			$last_month = floatval( $mrr_by_month[ count( $mrr_by_month ) - 1 ]['mrr'] ?? 0 );
			$prev_month = floatval( $mrr_by_month[ count( $mrr_by_month ) - 2 ]['mrr'] ?? 0 );
			if ( $prev_month > 0 ) {
				$mrr_growth = ( ( $last_month - $prev_month ) / $prev_month ) * 100;
			}
		}

		return array(
			'data' => array(
			'current_mrr' => floatval( $current_mrr['net_mrr'] ?? 0 ),
			'mrr_growth' => $mrr_growth,
			),
		);
	}

	/**
	 * Get churn rate summary.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Churn rate data.
	 */
	public static function get_churn_rate_summary( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		// Get total active customers at start of period
		$start_customers = $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT customer_id)
			FROM {$prefix}edd_orders
			WHERE status IN ('complete', 'edd_subscription')
			AND date_created < " . ( $start_date ? $wpdb->prepare( '%s', $start_date ) : 'CURDATE()' )
		);

		// Get churned customers (no orders in period)
		$churned = $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT c.id)
			FROM {$prefix}edd_customers c
			WHERE c.id IN (
				SELECT DISTINCT customer_id
				FROM {$prefix}edd_orders
				WHERE status IN ('complete', 'edd_subscription')
				AND date_created < " . ( $start_date ? $wpdb->prepare( '%s', $start_date ) : 'CURDATE()' ) . "
			)
			AND c.id NOT IN (
				SELECT DISTINCT customer_id
				FROM {$prefix}edd_orders
				WHERE status IN ('complete', 'edd_subscription')
				{$date_filter}
			)
			"
		);

		$churn_rate = $start_customers > 0 ? ( $churned / $start_customers ) * 100 : 0;

		return array(
			'data' => array(
			'churn_rate'   => round( $churn_rate, 2 ),
			'churned'      => intval( $churned ),
			'total_active' => intval( $start_customers ),
				'churn_change' => 0, // TODO: Calculate period-over-period change
			),
		);
	}

	/**
	 * Get customers revenue metrics.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Customer revenue metrics.
	 */
	public static function get_customers_revenue_metrics( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		$metrics = $wpdb->get_row(
			"
			SELECT
				COUNT(DISTINCT o.customer_id) AS total_customers,
				SUM(o.total) AS total_revenue,
				AVG(o.total) AS avg_order_value,
				SUM(o.total) / COUNT(DISTINCT o.customer_id) AS revenue_per_customer
			FROM {$prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
			{$date_filter}
			",
			ARRAY_A
		);

		return array(
			'data' => array(
			'total_customers'      => intval( $metrics->total_customers ?? 0 ),
			'total_revenue'        => floatval( $metrics->total_revenue ?? 0 ),
			'avg_order_value'      => floatval( $metrics->avg_order_value ?? 0 ),
			'revenue_per_customer' => floatval( $metrics->revenue_per_customer ?? 0 ),
			),
		);
	}

	/**
	 * Get MRR momentum and waterfall.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array MRR momentum data.
	 */
	public static function get_mrr_momentum( $start_date = null, $end_date = null ) {
		$current_mrr = self::get_current_mrr_breakdown();
		$mrr_by_month = self::get_mrr_by_month( $start_date, $end_date );

		// Calculate waterfall components
		$last_month = count( $mrr_by_month ) > 0 ? $mrr_by_month[ count( $mrr_by_month ) - 1 ] : null;
		$prev_month = count( $mrr_by_month ) > 1 ? $mrr_by_month[ count( $mrr_by_month ) - 2 ] : null;

		$starting_mrr = floatval( $prev_month['mrr'] ?? $current_mrr['net_mrr'] ?? 0 );
		$new_mrr = floatval( $current_mrr['new_mrr'] ?? 0 );
		$expansion_mrr = 0; // Would need subscription data to calculate
		$contraction_mrr = 0; // Would need subscription data to calculate
		$churned_mrr = floatval( $current_mrr['churned_mrr'] ?? 0 );
		$ending_mrr = floatval( $current_mrr['net_mrr'] ?? 0 );

		$mrr_growth_rate = $starting_mrr > 0 ? ( ( $ending_mrr - $starting_mrr ) / $starting_mrr ) * 100 : 0;
		$expansion_percentage = ($new_mrr + $expansion_mrr) > 0 ? round(($expansion_mrr / ($new_mrr + $expansion_mrr)) * 100, 1) : 0;

		return array(
			'data' => array(
			'currentMRR'         => $ending_mrr,
			'newMRR'             => $new_mrr,
			'expansionMRR'       => $expansion_mrr,
			'churnedMRR'         => $churned_mrr,
				'mrrGrowthRate'      => round($mrr_growth_rate, 2),
			'waterfall'          => array(
				$starting_mrr,
				$new_mrr,
				$expansion_mrr,
				0, // Reactivation placeholder
				-$contraction_mrr,
				-$churned_mrr,
				$ending_mrr,
				),
				'topGrowthDriver' => $new_mrr > $expansion_mrr ? 'new subscriptions' : 'expansion',
				'expansionPercentage' => $expansion_percentage,
				'contractionImpact' => $contraction_mrr > 0 ? ($contraction_mrr > $new_mrr ? 'high' : 'low') : 'none',
			),
		);
	}

	/**
	 * Get subscription cohort retention.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Cohort retention data.
	 */
	public static function get_subscription_cohort_retention( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		// Check if subscriptions table exists
		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
				'cohorts'     => array(),
				'heatmapData' => array(),
				),
			);
		}

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND s.created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND s.created <= %s", $end_date . ' 23:59:59' );
		}

		// Get cohorts
		$cohorts = $wpdb->get_col(
			"
			SELECT DISTINCT DATE_FORMAT(s.created, '%Y-%m') AS cohort
			FROM {$prefix}edd_subscriptions s
			WHERE s.status IN ('active', 'trialling')
			{$date_filter}
			ORDER BY cohort DESC
			LIMIT 12
			"
		);

		$heatmap_data = array();
		foreach ( $cohorts as $cohort_idx => $cohort ) {
			for ( $month = 0; $month < 12; $month++ ) {
				$retention = $wpdb->get_var(
					$wpdb->prepare(
						"
						SELECT COUNT(DISTINCT s.id)
						FROM {$prefix}edd_subscriptions s
						WHERE DATE_FORMAT(s.created, '%%Y-%%m') = %s
						AND s.status IN ('active', 'trialling')
						AND TIMESTAMPDIFF(MONTH, s.created, CURDATE()) >= %d
						",
						$cohort,
						$month
					)
				);

				$total = $wpdb->get_var(
					$wpdb->prepare(
						"
						SELECT COUNT(DISTINCT s.id)
						FROM {$prefix}edd_subscriptions s
						WHERE DATE_FORMAT(s.created, '%%Y-%%m') = %s
						",
						$cohort
					)
				);

				$retention_rate = $total > 0 ? ( $retention / $total ) * 100 : 0;
				$heatmap_data[] = array( $month, $cohort_idx, round( $retention_rate, 1 ) );
			}
		}

		// Calculate average retention rates
		$avg_three_month = 0;
		$avg_six_month = 0;
		$avg_twelve_month = 0;
		$retention_counts = array(0 => 0, 2 => 0, 5 => 0, 11 => 0);
		$retention_totals = array(0 => 0, 2 => 0, 5 => 0, 11 => 0);

		foreach ($heatmap_data as $data_point) {
			$month = $data_point[0];
			$retention = $data_point[2];
			if (isset($retention_counts[$month])) {
				$retention_counts[$month]++;
				$retention_totals[$month] += $retention;
			}
		}

		if ($retention_counts[2] > 0) {
			$avg_three_month = round($retention_totals[2] / $retention_counts[2], 2);
		}
		if ($retention_counts[5] > 0) {
			$avg_six_month = round($retention_totals[5] / $retention_counts[5], 2);
		}
		if ($retention_counts[11] > 0) {
			$avg_twelve_month = round($retention_totals[11] / $retention_counts[11], 2);
		}

		return array(
			'data' => array(
			'cohorts'     => $cohorts,
			'heatmapData' => $heatmap_data,
				'avgThreeMonthRetention' => $avg_three_month,
				'avgSixMonthRetention' => $avg_six_month,
				'avgTwelveMonthRetention' => $avg_twelve_month,
				'strongestCohort' => !empty($cohorts) ? $cohorts[0] : 'N/A',
				'dropoffMonth' => 3,
				'retentionGap' => 15,
			),
		);
	}

	/**
	 * Get subscription lifecycle flow.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Lifecycle flow data.
	 */
	public static function get_subscription_lifecycle_flow( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
				'nodes' => array(),
				'links' => array(),
				),
			);
		}

		$nodes = array(
			array( 'name' => 'Trial' ),
			array( 'name' => 'Active Paid' ),
			array( 'name' => 'Upgraded' ),
			array( 'name' => 'Downgraded' ),
			array( 'name' => 'Churned' ),
			array( 'name' => 'Reactivated' ),
		);

		// Simplified links - would need more complex logic for actual transitions
		$links = array(
			array( 'source' => 'Trial', 'target' => 'Active Paid', 'value' => 100 ),
			array( 'source' => 'Active Paid', 'target' => 'Upgraded', 'value' => 20 ),
			array( 'source' => 'Active Paid', 'target' => 'Churned', 'value' => 10 ),
		);

		// Calculate rates from links (simplified)
		$total_transitions = 130; // Sum of link values
		$trial_conversion = 100;
		$upgrade_count = 20;
		$downgrade_count = 0;
		$reactivation_count = 0;

		return array(
			'data' => array(
			'nodes' => $nodes,
			'links' => $links,
				'trialConversionRate' => $total_transitions > 0 ? round(($trial_conversion / $total_transitions) * 100, 2) : 0,
				'upgradeRate' => $total_transitions > 0 ? round(($upgrade_count / $total_transitions) * 100, 2) : 0,
				'downgradeRate' => $total_transitions > 0 ? round(($downgrade_count / $total_transitions) * 100, 2) : 0,
				'reactivationRate' => $total_transitions > 0 ? round(($reactivation_count / $total_transitions) * 100, 2) : 0,
			),
		);
	}

	/**
	 * Get dunning recovery metrics.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Dunning recovery data.
	 */
	public static function get_dunning_recovery_metrics( $start_date = null, $end_date = null ) {
		// This would typically integrate with payment gateway webhooks
		// For now, return placeholder structure
		return array(
			'data' => array(
			'failedPaymentRate'     => 0,
			'overallRecoveryRate'   => 0,
			'totalRevenueRecovered' => 0,
			'avgDaysToRecovery'     => 0,
			'recoveryRates'         => array( 65, 45, 30, 15 ),
			'revenueRecovered'      => array( 0, 0, 0, 0 ),
			'timelineDates'         => array(),
			'failedPayments'        => array(),
			'recoveredRevenue'      => array(),
			'timelineRecoveryRate'  => array(),
				'firstAttemptRate'      => 65,
				'potentialRecovery'     => '12,500',
				'optimizationPotential' => 15,
			),
		);
	}

	/**
	 * Get revenue by payment method.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Revenue by payment method.
	 */
	public static function get_revenue_by_payment_method( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		$results = $wpdb->get_results(
			"
			SELECT
				o.gateway AS payment_method,
				SUM(o.total) AS revenue,
				COUNT(o.id) AS total_orders,
				SUM(CASE WHEN o.status = 'complete' THEN 1 ELSE 0 END) AS successful_orders,
				(SUM(CASE WHEN o.status = 'complete' THEN 1 ELSE 0 END) / COUNT(o.id)) * 100 AS success_rate
			FROM {$prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription', 'failed', 'refunded')
			{$date_filter}
			GROUP BY o.gateway
			ORDER BY revenue DESC
			",
			ARRAY_A
		);

		return array(
			'data' => $results,
		);
	}

	/**
	 * Get cohort revenue heatmap.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Cohort revenue data.
	 */
	public static function get_cohort_revenue_heatmap( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		// Get customer cohorts
		$cohorts = $wpdb->get_col(
			"
			SELECT DISTINCT DATE_FORMAT(MIN(o.date_created), '%Y-%m') AS cohort
			FROM {$prefix}edd_orders o
			WHERE o.status IN ('complete', 'edd_subscription')
			{$date_filter}
			GROUP BY o.customer_id
			ORDER BY cohort DESC
			LIMIT 12
			"
		);

		$data = array();
		foreach ( $cohorts as $cohort_idx => $cohort ) {
			for ( $month = 0; $month < 12; $month++ ) {
				$revenue = $wpdb->get_var(
					$wpdb->prepare(
						"
						SELECT SUM(o.total)
						FROM {$prefix}edd_orders o
						INNER JOIN (
							SELECT customer_id, MIN(date_created) AS first_order
							FROM {$prefix}edd_orders
							WHERE status IN ('complete', 'edd_subscription')
							GROUP BY customer_id
							HAVING DATE_FORMAT(first_order, '%%Y-%%m') = %s
						) AS cohorts ON o.customer_id = cohorts.customer_id
						WHERE o.status IN ('complete', 'edd_subscription')
						AND TIMESTAMPDIFF(MONTH, cohorts.first_order, o.date_created) = %d
						{$date_filter}
						",
						$cohort,
						$month
					)
				);

				$data[] = array(
					'cohort'        => $cohort,
					'month_number'  => $month,
					'revenue'       => floatval( $revenue ?? 0 ),
				);
			}
		}

		return array(
			'data' => $data,
		);
	}

	/**
	 * Get revenue projections.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Revenue projections.
	 */
	public static function get_revenue_projections( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		// Get recent daily averages
		$daily_avg = $wpdb->get_var(
			"
			SELECT AVG(daily_revenue)
			FROM (
				SELECT DATE(date_created) AS day, SUM(total) AS daily_revenue
				FROM {$prefix}edd_orders
				WHERE status IN ('complete', 'edd_subscription')
				AND date_created >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
				GROUP BY DATE(date_created)
			) AS daily
			"
		);

		$current_revenue = floatval( $daily_avg ?? 0 ) * 30; // Current monthly run rate
		$growth_rate = 0.05; // 5% assumed growth

		return array(
			'data' => array(
				'velocity' => $growth_rate * 100, // Daily growth rate percentage
				'projections' => array(
					'current'      => $current_revenue,
					'day_30'       => $current_revenue * ( 1 + $growth_rate ),
					'day_60'       => $current_revenue * pow( 1 + $growth_rate, 2 ),
					'day_90'       => $current_revenue * pow( 1 + $growth_rate, 3 ),
					'day_30_best'  => $current_revenue * ( 1 + $growth_rate * 1.5 ),
					'day_60_best'  => $current_revenue * pow( 1 + $growth_rate * 1.5, 2 ),
					'day_90_best'  => $current_revenue * pow( 1 + $growth_rate * 1.5, 3 ),
					'day_30_worst' => $current_revenue * ( 1 + $growth_rate * 0.5 ),
					'day_60_worst' => $current_revenue * pow( 1 + $growth_rate * 0.5, 2 ),
					'day_90_worst' => $current_revenue * pow( 1 + $growth_rate * 0.5, 3 ),
				),
			),
		);
	}

	/**
	 * Get revenue by customer segments.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Revenue by segments.
	 */
	public static function get_revenue_by_customer_segments( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		// Segment by customer lifetime value
		$results = $wpdb->get_results(
			"
			SELECT
				CASE
					WHEN customer_total.total_spent >= 1000 THEN 'High Value'
					WHEN customer_total.total_spent >= 500 THEN 'Medium Value'
					ELSE 'Low Value'
				END AS segment,
				SUM(o.total) AS revenue,
				COUNT(DISTINCT o.customer_id) AS customers
			FROM {$prefix}edd_orders o
			INNER JOIN (
				SELECT customer_id, SUM(total) AS total_spent
				FROM {$prefix}edd_orders
				WHERE status IN ('complete', 'edd_subscription')
				GROUP BY customer_id
			) AS customer_total ON o.customer_id = customer_total.customer_id
			WHERE o.status IN ('complete', 'edd_subscription')
			{$date_filter}
			GROUP BY segment
			ORDER BY revenue DESC
			",
			ARRAY_A
		);

		return array(
			'data' => $results,
		);
	}

	/**
	 * Get burn rate analysis.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Burn rate data.
	 */
	public static function get_burn_rate_analysis( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND o.date_created <= %s", $end_date . ' 23:59:59' );
		}

		// Calculate monthly burn (expenses would come from another source)
		$monthly_revenue = $wpdb->get_var(
			"
			SELECT SUM(total) / TIMESTAMPDIFF(MONTH, MIN(date_created), MAX(date_created) + INTERVAL 1 DAY)
			FROM {$prefix}edd_orders
			WHERE status IN ('complete', 'edd_subscription')
			{$date_filter}
			"
		);

		return array(
			'monthly_burn'     => 0, // Would need expense data
			'monthly_revenue'  => floatval( $monthly_revenue ?? 0 ),
			'net_burn'         => -floatval( $monthly_revenue ?? 0 ),
			'runway_months'    => 0, // Would need cash balance
		);
	}

	/**
	 * Get runway calculation.
	 *
	 * @return array Runway data.
	 */
	public static function get_runway_calculation() {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		// Get average monthly revenue
		$monthly_revenue = $wpdb->get_var(
			"
			SELECT AVG(monthly_total)
			FROM (
				SELECT DATE_FORMAT(date_created, '%Y-%m') AS month, SUM(total) AS monthly_total
				FROM {$prefix}edd_orders
				WHERE status IN ('complete', 'edd_subscription')
				AND date_created >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
				GROUP BY DATE_FORMAT(date_created, '%Y-%m')
			) AS monthly
			"
		);

		// Placeholder for cash balance (would come from accounting system)
		$cash_balance = 0;
		$monthly_burn = 0; // Would need expense data

		$runway = ( $monthly_burn > 0 && $monthly_revenue < $monthly_burn ) 
			? $cash_balance / ( $monthly_burn - $monthly_revenue ) 
			: 999;

		return array(
			'runway_months'    => round( $runway, 1 ),
			'cash_balance'     => $cash_balance,
			'monthly_burn'     => $monthly_burn,
			'monthly_revenue'  => floatval( $monthly_revenue ?? 0 ),
		);
	}

	/**
	 * Get subscription churn analysis.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Churn analysis data.
	 */
	public static function get_subscription_churn_analysis( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
				'overallChurnRate'      => 0,
				'voluntaryChurnRate'    => 0,
				'involuntaryChurnRate'  => 0,
				'winbackRate'           => 0,
				'dates'                 => array(),
				'voluntary'             => array(),
				'involuntary'           => array(),
				'total'                 => array(),
				'reasons'               => array(),
					'topChurnReason'        => 'Unknown',
					'involuntaryPercentage' => 0,
					'bestWinbackSegment'    => 'N/A',
				),
			);
		}

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND s.expiration >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND s.expiration <= %s", $end_date . ' 23:59:59' );
		}

		// Get churned subscriptions
		$churned = $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$prefix}edd_subscriptions s
			WHERE s.status = 'cancelled'
			{$date_filter}
			"
		);

		$total_active = $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$prefix}edd_subscriptions s
			WHERE s.status IN ('active', 'trialling')
			"
		);

		$churn_rate = ( $total_active + $churned ) > 0 
			? ( $churned / ( $total_active + $churned ) ) * 100 
			: 0;

		return array(
			'data' => array(
			'overallChurnRate'     => round( $churn_rate, 2 ),
			'voluntaryChurnRate'   => round( $churn_rate * 0.7, 2 ), // Estimate
			'involuntaryChurnRate' => round( $churn_rate * 0.3, 2 ), // Estimate
			'winbackRate'          => 0,
			'dates'                => array(),
			'voluntary'            => array(),
			'involuntary'          => array(),
			'total'                => array(),
			'reasons'              => array(),
			'topChurnReason'       => 'Unknown',
				'involuntaryPercentage' => 30,
				'bestWinbackSegment'   => 'annual plans',
			),
		);
	}

	/**
	 * Get cohort retention analysis.
	 *
	 * @param int $months Number of months to analyze.
	 * @return array Cohort retention data.
	 */
	public static function get_cohort_retention_analysis( $months = 12 ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'avgThreeMonthRetention'  => 0,
				'avgSixMonthRetention'    => 0,
				'avgTwelveMonthRetention' => 0,
				'cohorts'                 => array(),
				'heatmapData'             => array(),
			);
		}

		// This is a simplified version - full implementation would track retention over time
		return self::get_subscription_cohort_retention();
	}

	/**
	 * Get reactivation rates.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Reactivation data.
	 */
	public static function get_reactivation_rates( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'reactivation_rate' => 0,
				'reactivated_count' => 0,
			);
		}

		$date_filter = '';
		if ( $start_date ) {
			$date_filter .= $wpdb->prepare( " AND s.modified >= %s", $start_date );
		}
		if ( $end_date ) {
			$date_filter .= $wpdb->prepare( " AND s.modified <= %s", $end_date . ' 23:59:59' );
		}

		// Get reactivated subscriptions (status changed from cancelled to active)
		$reactivated = $wpdb->get_var(
			"
			SELECT COUNT(*)
			FROM {$prefix}edd_subscriptions s
			WHERE s.status = 'active'
			AND s.id IN (
				SELECT subscription_id
				FROM {$prefix}edd_subscription_meta
				WHERE meta_key = 'status'
				AND meta_value = 'cancelled'
			)
			{$date_filter}
			"
		);

		return array(
			'reactivation_rate'  => 0, // Would need total cancelled to calculate
			'reactivated_count' => intval( $reactivated ),
		);
	}

	/**
	 * Get upgrade and downgrade trends.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Upgrade/downgrade data.
	 */
	public static function get_upgrade_downgrade_trends( $start_date = null, $end_date = null ) {
		$wpdb = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
				'planNames'              => array(),
				'matrixData'             => array(),
				'maxMovement'            => 0,
				'netUpgrades'            => 0,
				'upgradeRevenueImpact'   => 0,
				'downgradeRevenueImpact' => 0,
				),
			);
		}

		// Get all plan names
		$plan_names = $wpdb->get_col(
			"
			SELECT DISTINCT product_name
			FROM {$prefix}edd_subscriptions
			WHERE product_name IS NOT NULL
			ORDER BY product_name
			LIMIT 10
			"
		);

		if ( empty( $plan_names ) ) {
			$plan_names = array( 'Basic', 'Pro', 'Premium', 'Enterprise' );
		}

		// Build matrix (simplified - would need actual plan change tracking)
		$matrix_data = array();
		foreach ( $plan_names as $from_idx => $from_plan ) {
			foreach ( $plan_names as $to_idx => $to_plan ) {
				$matrix_data[] = array( $to_idx, $from_idx, $from_plan === $to_plan ? 10 : 0 );
			}
		}

		return array(
			'data' => array(
			'planNames'              => $plan_names,
			'matrixData'             => $matrix_data,
			'maxMovement'            => 10,
			'netUpgrades'            => 0,
			'upgradeRevenueImpact'   => 0,
			'downgradeRevenueImpact' => 0,
			),
		);
	}

	/**
	 * Get comprehensive churn data.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Comprehensive churn data.
	 */
	public static function get_churn_comprehensive( $start_date = null, $end_date = null ) {
		$wpdb   = self::get_db();
		$prefix = self::get_table_prefix();

		// Set default dates if not provided.
		if ( ! $start_date ) {
			$start_date = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
		}
		if ( ! $end_date ) {
			$end_date = gmdate( 'Y-m-d' );
		}

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return self::get_empty_churn_comprehensive();
		}

		// Get active subscribers at start of period.
		$active_at_start = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT customer_id)
				FROM {$prefix}edd_subscriptions
				WHERE status = 'active'
				AND created <= %s
				AND (expiration > %s OR expiration = '0000-00-00 00:00:00' OR expiration IS NULL)
				",
				$start_date,
				$start_date
			)
		);

		// Get canceled during period.
		$canceled = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT customer_id)
				FROM {$prefix}edd_subscriptions
				WHERE status = 'cancelled'
				AND date_modified BETWEEN %s AND %s
				",
				$start_date,
				$end_date . ' 23:59:59'
			)
		);

		// Get expired during period.
		$expired = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT customer_id)
				FROM {$prefix}edd_subscriptions
				WHERE status = 'expired'
				AND date_modified BETWEEN %s AND %s
				",
				$start_date,
				$end_date . ' 23:59:59'
			)
		);

		$total_churned = intval( $canceled ) + intval( $expired );
		$active_at_start = max( 1, intval( $active_at_start ) ); // Avoid division by zero.
		$churn_rate = round( ( $total_churned / $active_at_start ) * 100, 2 );

		// Get current active subscribers.
		$current_active = $wpdb->get_var(
			"
			SELECT COUNT(DISTINCT customer_id)
			FROM {$prefix}edd_subscriptions
			WHERE status = 'active'
			"
		);

		// Get monthly trend for the period.
		$monthly_churn = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT 
					DATE_FORMAT(date_modified, '%%Y-%%m') as month,
					COUNT(DISTINCT CASE WHEN status = 'cancelled' THEN customer_id END) as canceled,
					COUNT(DISTINCT CASE WHEN status = 'expired' THEN customer_id END) as expired
				FROM {$prefix}edd_subscriptions
				WHERE status IN ('cancelled', 'expired')
				AND date_modified BETWEEN %s AND %s
				GROUP BY DATE_FORMAT(date_modified, '%%Y-%%m')
				ORDER BY month ASC
				",
				$start_date,
				$end_date . ' 23:59:59'
			),
			ARRAY_A
		);

		// Get churn by reason (using cancellation notes if available).
		$churn_reasons = array(
			array( 'reason' => 'Voluntary', 'count' => intval( $canceled ), 'percentage' => $total_churned > 0 ? round( ( $canceled / $total_churned ) * 100, 1 ) : 0 ),
			array( 'reason' => 'Involuntary (Expired)', 'count' => intval( $expired ), 'percentage' => $total_churned > 0 ? round( ( $expired / $total_churned ) * 100, 1 ) : 0 ),
		);

		// Calculate annualized churn rate.
		$months_in_period = max( 1, round( ( strtotime( $end_date ) - strtotime( $start_date ) ) / ( 30 * 24 * 60 * 60 ) ) );
		$monthly_avg_churn = $churn_rate / $months_in_period;
		$annualized_churn = round( ( 1 - pow( 1 - ( $monthly_avg_churn / 100 ), 12 ) ) * 100, 2 );

		// Get previous period for comparison.
		$period_days = ( strtotime( $end_date ) - strtotime( $start_date ) ) / ( 24 * 60 * 60 );
		$prev_start = gmdate( 'Y-m-d', strtotime( $start_date ) - ( $period_days * 24 * 60 * 60 ) );
		$prev_end = gmdate( 'Y-m-d', strtotime( $start_date ) - 1 );

		$prev_churned = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT customer_id)
				FROM {$prefix}edd_subscriptions
				WHERE status IN ('cancelled', 'expired')
				AND date_modified BETWEEN %s AND %s
				",
				$prev_start,
				$prev_end . ' 23:59:59'
			)
		);

		$prev_churned = intval( $prev_churned );
		$churn_change = $total_churned - $prev_churned;
		$churn_change_percent = $prev_churned > 0 ? round( ( $churn_change / $prev_churned ) * 100, 1 ) : 0;

		return array(
			'data' => array(
				'summary'          => array(
					'churn_rate'            => $churn_rate,
					'annualized_churn'      => $annualized_churn,
					'total_churned'         => $total_churned,
					'canceled'              => intval( $canceled ),
					'expired'               => intval( $expired ),
					'active_at_start'       => intval( $active_at_start ),
					'current_active'        => intval( $current_active ),
					'retention_rate'        => round( 100 - $churn_rate, 2 ),
					'churn_change'          => $churn_change,
					'churn_change_percent'  => $churn_change_percent,
				),
				'monthly_trend'    => $monthly_churn,
				'churn_by_reason'  => $churn_reasons,
				'period'           => array(
					'start_date' => $start_date,
					'end_date'   => $end_date,
					'months'     => $months_in_period,
				),
			),
		);
	}

	/**
	 * Get empty churn comprehensive data.
	 *
	 * @return array Empty churn data.
	 */
	private static function get_empty_churn_comprehensive() {
		return array(
			'data' => array(
				'summary'          => array(
					'churn_rate'            => 0,
					'annualized_churn'      => 0,
					'total_churned'         => 0,
					'canceled'              => 0,
					'expired'               => 0,
					'active_at_start'       => 0,
					'current_active'        => 0,
					'retention_rate'        => 100,
					'churn_change'          => 0,
					'churn_change_percent'  => 0,
				),
				'monthly_trend'    => array(),
				'churn_by_reason'  => array(),
				'period'           => array(
					'start_date' => '',
					'end_date'   => '',
					'months'     => 0,
				),
			),
		);
	}

	/**
	 * Get monthly churn trends.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Monthly churn trends.
	 */
	public static function get_churn_monthly_trends( $start_date = null, $end_date = null ) {
		$wpdb   = self::get_db();
		$prefix = self::get_table_prefix();

		// Set default dates if not provided.
		if ( ! $start_date ) {
			$start_date = gmdate( 'Y-m-d', strtotime( '-24 months' ) );
		}
		if ( ! $end_date ) {
			$end_date = gmdate( 'Y-m-d' );
		}

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
					'trends'        => array(),
					'average_churn' => 0,
					'total_churned' => 0,
					'period'        => array(
						'start_date' => $start_date,
						'end_date'   => $end_date,
					),
				),
			);
		}

		// Get monthly churn data with running active counts.
		$trends = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT 
					DATE_FORMAT(m.month_date, '%%Y-%%m') as month,
					DATE_FORMAT(m.month_date, '%%b %%Y') as label,
					COALESCE(churned.total, 0) as churned,
					COALESCE(churned.canceled, 0) as canceled,
					COALESCE(churned.expired, 0) as expired,
					COALESCE(created.new_subs, 0) as new_subscriptions
				FROM (
					SELECT DATE_FORMAT(date_modified, '%%Y-%%m-01') as month_date
					FROM {$prefix}edd_subscriptions
					WHERE date_modified BETWEEN %s AND %s
					GROUP BY DATE_FORMAT(date_modified, '%%Y-%%m-01')
					UNION
					SELECT DATE_FORMAT(created, '%%Y-%%m-01') as month_date
					FROM {$prefix}edd_subscriptions
					WHERE created BETWEEN %s AND %s
					GROUP BY DATE_FORMAT(created, '%%Y-%%m-01')
				) m
				LEFT JOIN (
					SELECT 
						DATE_FORMAT(date_modified, '%%Y-%%m-01') as month_date,
						COUNT(DISTINCT customer_id) as total,
						COUNT(DISTINCT CASE WHEN status = 'cancelled' THEN customer_id END) as canceled,
						COUNT(DISTINCT CASE WHEN status = 'expired' THEN customer_id END) as expired
					FROM {$prefix}edd_subscriptions
					WHERE status IN ('cancelled', 'expired')
					AND date_modified BETWEEN %s AND %s
					GROUP BY DATE_FORMAT(date_modified, '%%Y-%%m-01')
				) churned ON m.month_date = churned.month_date
				LEFT JOIN (
					SELECT 
						DATE_FORMAT(created, '%%Y-%%m-01') as month_date,
						COUNT(*) as new_subs
					FROM {$prefix}edd_subscriptions
					WHERE created BETWEEN %s AND %s
					GROUP BY DATE_FORMAT(created, '%%Y-%%m-01')
				) created ON m.month_date = created.month_date
				ORDER BY m.month_date ASC
				",
				$start_date,
				$end_date . ' 23:59:59',
				$start_date,
				$end_date . ' 23:59:59',
				$start_date,
				$end_date . ' 23:59:59',
				$start_date,
				$end_date . ' 23:59:59'
			),
			ARRAY_A
		);

		// Calculate churn rates for each month.
		$running_active = 0;
		$processed_trends = array();

		foreach ( $trends as &$trend ) {
			$running_active += intval( $trend['new_subscriptions'] ) - intval( $trend['churned'] );
			$running_active = max( 0, $running_active );

			$churn_rate = $running_active > 0 ? round( ( intval( $trend['churned'] ) / max( 1, $running_active + intval( $trend['churned'] ) ) ) * 100, 2 ) : 0;

			$processed_trends[] = array(
				'month'             => $trend['month'],
				'label'             => $trend['label'],
				'churned'           => intval( $trend['churned'] ),
				'canceled'          => intval( $trend['canceled'] ),
				'expired'           => intval( $trend['expired'] ),
				'new_subscriptions' => intval( $trend['new_subscriptions'] ),
				'churn_rate'        => $churn_rate,
				'active_end'        => $running_active,
			);
		}

		// Calculate average churn.
		$total_churn = array_sum( array_column( $processed_trends, 'churned' ) );
		$avg_churn = count( $processed_trends ) > 0 ? round( $total_churn / count( $processed_trends ), 1 ) : 0;

		return array(
			'data' => array(
				'trends'        => $processed_trends,
				'average_churn' => $avg_churn,
				'total_churned' => $total_churn,
				'period'        => array(
					'start_date' => $start_date,
					'end_date'   => $end_date,
				),
			),
		);
	}

	/**
	 * Get retention cohort heatmap data.
	 *
	 * @param int $max_years Maximum years to track (default: 6).
	 * @return array Cohort heatmap data.
	 */
	public static function get_retention_cohort_heatmap( $max_years = 6 ) {
		$wpdb   = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
					'cohorts'      => array(),
					'max_years'    => $max_years,
					'current_year' => gmdate( 'Y' ),
				),
			);
		}

		$current_year = intval( gmdate( 'Y' ) );
		$cohorts = array();

		// Get signup years with customer counts AND recurring subscription counts.
		// Count ALL customers who had their first subscription in each year (regardless of type)
		// But only count recurring subscriptions (exclude lifetime purchases)
		$signup_years = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT 
					YEAR(first_sub.created) as signup_year,
					COUNT(DISTINCT first_sub.customer_id) as total_customers,
					SUM(CASE WHEN first_sub.recurring_amount > 0 THEN 1 ELSE 0 END) as total_subscriptions
				FROM (
					SELECT 
						s.customer_id,
						s.created,
						s.recurring_amount
					FROM {$prefix}edd_subscriptions s
					INNER JOIN (
						SELECT 
							customer_id,
							MIN(created) as first_created
						FROM {$prefix}edd_subscriptions
						WHERE YEAR(created) >= %d
						GROUP BY customer_id
					) as first_dates ON s.customer_id = first_dates.customer_id 
						AND s.created = first_dates.first_created
					WHERE YEAR(s.created) >= %d
				) as first_sub
				GROUP BY YEAR(first_sub.created)
				ORDER BY signup_year ASC
				",
				$current_year - $max_years + 1,
				$current_year - $max_years + 1
			),
			ARRAY_A
		);

		foreach ( $signup_years as $year_data ) {
			$signup_year = intval( $year_data['signup_year'] );
			$total_customers = intval( $year_data['total_customers'] );

			if ( $total_customers === 0 ) {
				continue;
			}

			$churn_rates = array();
			$years_to_track = min( $max_years, $current_year - $signup_year + 1 );

			for ( $year_offset = 1; $year_offset <= $years_to_track; $year_offset++ ) {
				$check_year = $signup_year + $year_offset;

				if ( $check_year > $current_year ) {
					break;
				}

				// Count customers still active at the end of check_year.
				$still_active = $wpdb->get_var(
					$wpdb->prepare(
						"
						SELECT COUNT(DISTINCT customer_id)
						FROM {$prefix}edd_subscriptions
						WHERE YEAR(created) = %d
						AND (
							status = 'active'
							OR (
								status IN ('cancelled', 'expired')
								AND YEAR(date_modified) > %d
							)
						)
						",
						$signup_year,
						$check_year
					)
				);

				$churned = $total_customers - intval( $still_active );
				$churn_rate = round( ( $churned / $total_customers ) * 100, 1 );

				$churn_rates[ 'year_' . $year_offset ] = $churn_rate;
			}

			$cohorts[] = array(
				'signup_year'   => $signup_year,
				'customers'     => $total_customers,
				'subscriptions' => intval( $year_data['total_subscriptions'] ),
				'churn_rates'   => $churn_rates,
			);
		}

		return array(
			'data' => array(
				'cohorts'      => $cohorts,
				'max_years'    => $max_years,
				'current_year' => $current_year,
			),
		);
	}

	/**
	 * Get customer count for a specific cohort year.
	 * Used for temporary purpose.
	 *
	 * @param int $year The year to get customer count for (e.g., 2023).
	 * @return array Customer count data for the specified year.
	 */
	public static function get_cohort_customer_count( $year ) {
		$wpdb   = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'success' => false,
				'message' => 'Subscriptions table does not exist',
				'data'    => array(
					'year'     => $year,
					'customers' => 0,
				),
			);
		}

		$year = intval( $year );

		// Build cache key
		$cache_key = 'cohort_customer_count_' . $year;

		// Query to get customer count (all customers) and FIRST recurring subscription count for the specific year
		// Count ALL customers who had their first subscription in 2023 (regardless of type)
		// But only count recurring subscriptions (exclude lifetime purchases)
		$query = $wpdb->prepare(
			"
			SELECT 
				YEAR(first_sub.created) as signup_year,
				COUNT(DISTINCT first_sub.customer_id) as total_customers,
				SUM(CASE WHEN first_sub.recurring_amount > 0 THEN 1 ELSE 0 END) as total_subscriptions
			FROM (
				SELECT 
					s.customer_id,
					s.created,
					s.recurring_amount
				FROM {$prefix}edd_subscriptions s
				INNER JOIN (
					SELECT 
						customer_id,
						MIN(created) as first_created
					FROM {$prefix}edd_subscriptions
					WHERE YEAR(created) = %d
					GROUP BY customer_id
				) as first_dates ON s.customer_id = first_dates.customer_id 
					AND s.created = first_dates.first_created
				WHERE YEAR(s.created) = %d
			) as first_sub
			GROUP BY YEAR(first_sub.created)
			",
			$year,
			$year
		);

		// get_cached returns array format (ARRAY_A), so get first row
		$results = self::get_cached( $cache_key, $query );
		$result = ! empty( $results ) && is_array( $results ) ? $results[0] : null;

		if ( ! $result || empty( $result ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'year'              => $year,
					'customers'         => 0,
					'total_subscriptions' => 0,
				),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'year'                => isset( $result['signup_year'] ) ? intval( $result['signup_year'] ) : $year,
				'customers'           => isset( $result['total_customers'] ) ? intval( $result['total_customers'] ) : 0,
				'total_subscriptions' => isset( $result['total_subscriptions'] ) ? intval( $result['total_subscriptions'] ) : 0,
			),
		);
	}

	/**
	 * Get retention curve data.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array Retention curve data.
	 */
	public static function get_retention_curve( $start_date = null, $end_date = null ) {
		$wpdb   = self::get_db();
		$prefix = self::get_table_prefix();

		$subscriptions_table = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}edd_subscriptions'" );
		if ( ! $subscriptions_table ) {
			return array(
				'data' => array(
					'curve'            => array(),
					'average_lifetime' => 0,
				),
			);
		}

		// Calculate retention by subscription age in months.
		$retention_data = array();

		for ( $months = 0; $months <= 24; $months++ ) {
			// Count subscriptions that were active at this age.
			$active_at_age = $wpdb->get_var(
				$wpdb->prepare(
					"
					SELECT COUNT(*)
					FROM {$prefix}edd_subscriptions
					WHERE TIMESTAMPDIFF(MONTH, created, COALESCE(
						CASE WHEN status IN ('cancelled', 'expired') THEN date_modified ELSE NOW() END,
						NOW()
					)) >= %d
					",
					$months
				)
			);

			// Total subscriptions ever created.
			$total_ever = $wpdb->get_var(
				"
				SELECT COUNT(*)
				FROM {$prefix}edd_subscriptions
				"
			);

			$retention_rate = $total_ever > 0 ? round( ( intval( $active_at_age ) / intval( $total_ever ) ) * 100, 1 ) : 0;

			$retention_data[] = array(
				'month'          => $months,
				'label'          => $months === 0 ? 'Start' : "Month $months",
				'retained'       => intval( $active_at_age ),
				'retention_rate' => $retention_rate,
			);
		}

		// Calculate average lifetime (months until churn).
		$avg_lifetime = $wpdb->get_var(
			"
			SELECT AVG(TIMESTAMPDIFF(MONTH, created, 
				CASE WHEN status IN ('cancelled', 'expired') THEN date_modified ELSE NOW() END
			))
			FROM {$prefix}edd_subscriptions
			"
		);

		return array(
			'data' => array(
				'curve'            => $retention_data,
				'average_lifetime' => round( floatval( $avg_lifetime ), 1 ),
			),
		);
	}
}
