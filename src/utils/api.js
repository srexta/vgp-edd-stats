import { format } from 'date-fns';

// Get API configuration from WordPress localized script
const getConfig = () => {
	if (typeof window.vgpEddStats !== 'undefined') {
		return window.vgpEddStats;
	}

	// Fallback for development
	return {
		apiUrl: '/wp-json/vgp-edd-stats/v1',
		nonce: '',
		dateFormat: 'F j, Y',
		currencyCode: 'USD',
	};
};

/**
 * Make API request.
 *
 * @param {string} endpoint API endpoint.
 * @param {Object} params   Query parameters.
 * @return {Promise} Response data.
 */
export async function apiRequest(endpoint, params = {}) {
	const config = getConfig();
	const url = new URL(`${config.apiUrl}${endpoint}`);

	// Add query parameters
	Object.keys(params).forEach(key => {
		if (params[key] !== null && params[key] !== undefined) {
			url.searchParams.append(key, params[key]);
		}
	});

	const response = await fetch(url, {
		method: 'GET',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': config.nonce,
		},
	});

	if (!response.ok) {
		throw new Error(`API request failed: ${response.statusText}`);
	}

	const data = await response.json();

	if (!data.success) {
		throw new Error(data.message || 'API request failed');
	}

	return data.data;
}

/**
 * Format date range for API.
 *
 * @param {Object} dateRange Date range object with startDate and endDate.
 * @return {Object} Formatted date parameters.
 */
export function formatDateRange(dateRange) {
	if (!dateRange || !dateRange.startDate || !dateRange.endDate) {
		return {};
	}

	return {
		start_date: format(dateRange.startDate, 'yyyy-MM-dd'),
		end_date: format(dateRange.endDate, 'yyyy-MM-dd'),
	};
}

/**
 * Get currency symbol.
 *
 * @return {string} Currency symbol.
 */
export function getCurrencySymbol() {
	const config = getConfig();
	const symbols = {
		USD: '$',
		EUR: '€',
		GBP: '£',
		JPY: '¥',
	};

	return symbols[config.currencyCode] || '$';
}

/**
 * Format currency value.
 *
 * @param {number} value Currency value.
 * @return {string} Formatted currency string.
 */
export function formatCurrency(value) {
	const symbol = getCurrencySymbol();
	const formatted = new Intl.NumberFormat('en-US', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	}).format(Math.abs(value));

	return value < 0 ? `-${symbol}${formatted}` : `${symbol}${formatted}`;
}

/**
 * Format number with commas.
 *
 * @param {number} value Number value.
 * @return {string} Formatted number string.
 */
export function formatNumber(value) {
	return new Intl.NumberFormat('en-US').format(value);
}

/**
 * Format percentage.
 *
 * @param {number} value Percentage value.
 * @param {number} decimals Number of decimal places.
 * @return {string} Formatted percentage string.
 */
export function formatPercentage(value, decimals = 1) {
	return `${value.toFixed(decimals)}%`;
}

// API endpoints
export const API = {
	// Customers and Revenue
	getCustomersByMonth: (dateRange) =>
		apiRequest('/customers/by-month', formatDateRange(dateRange)),

	getCustomersYoY: () =>
		apiRequest('/customers/yoy-change'),

	getRevenueByMonth: (dateRange) =>
		apiRequest('/revenue/by-month', formatDateRange(dateRange)),

	getRefundedRevenue: (dateRange) =>
		apiRequest('/revenue/refunded', formatDateRange(dateRange)),

	// MRR and Growth
	getMRRByMonth: (dateRange) =>
		apiRequest('/mrr/by-month', formatDateRange(dateRange)),

	getCurrentMRR: () =>
		apiRequest('/mrr/current'),

	// Renewals
	getRenewalRates: (dateRange) =>
		apiRequest('/renewals/rates', formatDateRange(dateRange)),

	getUpcomingRenewals: (days = 30) =>
		apiRequest('/renewals/upcoming', { days }),

	// Refunds
    getRefundRates: (dateRange) =>
        apiRequest('/refunds/rates', formatDateRange(dateRange)),

    getNewCustomerRefundsByYear: (dateRange) =>
        apiRequest('/refunds/new-customers-yearly', formatDateRange(dateRange)),

	// Licensing
	getTopLicenses: (limit = 20) =>
		apiRequest('/licenses/top', { limit }),

    // Customer Analytics
    // New, server-backed endpoints
    getCustomerCLV: (limit = 50) =>
        apiRequest('/customers/clv', { limit }),

    getCustomerRFMReal: () =>
        apiRequest('/customers/rfm'),

    getCustomerHealthReal: () =>
        apiRequest('/customers/health'),

    // Legacy placeholders (unused now)
    getCustomerLifetimeValue: (dateRange) =>
        apiRequest('/customers/lifetime-value', formatDateRange(dateRange)),

	getCustomerRetentionRate: (dateRange) =>
		apiRequest('/customers/retention-rate', formatDateRange(dateRange)),

	getCustomerChurnAnalysis: (dateRange) =>
		apiRequest('/customers/churn-analysis', formatDateRange(dateRange)),

	getCustomerSegmentation: (dateRange) =>
		apiRequest('/customers/segmentation', formatDateRange(dateRange)),

    getCustomerRFM: (dateRange) =>
        apiRequest('/customers/rfm', formatDateRange(dateRange)),

	getAverageOrderValue: (dateRange) =>
		apiRequest('/customers/aov', formatDateRange(dateRange)),

    getCustomerHealth: (dateRange) =>
        apiRequest('/customers/health', formatDateRange(dateRange)),

    // Product Performance
    getTopProducts: (dateRange, limit = 10) =>
        apiRequest('/products/top', { ...formatDateRange(dateRange), limit }),

    getProductMatrix: (dateRange) =>
        apiRequest('/products/matrix', formatDateRange(dateRange)),

    getProductLifecycle: () =>
        apiRequest('/products/lifecycle'),

	getProductRevenueTrend: (productId, dateRange) =>
		apiRequest('/products/revenue-trend', { product_id: productId, ...formatDateRange(dateRange) }),

	getProductConversionRates: (dateRange) =>
		apiRequest('/products/conversion-rates', formatDateRange(dateRange)),

	getProductBundleAnalysis: (dateRange) =>
		apiRequest('/products/bundle-analysis', formatDateRange(dateRange)),

	getProductSeasonality: (productId) =>
		apiRequest('/products/seasonality', { product_id: productId }),

	// Revenue Analytics
	getRevenueBySource: (dateRange) =>
		apiRequest('/revenue/by-source', formatDateRange(dateRange)),

	getRevenueByProduct: (dateRange) =>
		apiRequest('/revenue/by-product', formatDateRange(dateRange)),

	getRevenueByCustomerType: (dateRange) =>
		apiRequest('/revenue/by-customer-type', formatDateRange(dateRange)),

	getRecurringRevenue: (dateRange) =>
		apiRequest('/revenue/recurring-breakdown', formatDateRange(dateRange)),

	getRevenueGrowthRate: (dateRange) =>
		apiRequest('/revenue/growth-rate', formatDateRange(dateRange)),

	// Financial Metrics
	getARPU: (dateRange) =>
		apiRequest('/metrics/arpu', formatDateRange(dateRange)),

	getCustomerAcquisitionCost: (dateRange) =>
		apiRequest('/metrics/cac', formatDateRange(dateRange)),

	getLTVtoCACRatio: (dateRange) =>
		apiRequest('/metrics/ltv-cac-ratio', formatDateRange(dateRange)),

	getGrossMargin: (dateRange) =>
		apiRequest('/metrics/gross-margin', formatDateRange(dateRange)),

	getNetRevenueRetention: (dateRange) =>
		apiRequest('/metrics/net-revenue-retention', formatDateRange(dateRange)),

	// Predictive Analytics
	getRevenueProjection: (months = 12) =>
		apiRequest('/predictive/revenue-projection', { months }),

	getChurnPrediction: () =>
		apiRequest('/predictive/churn-prediction'),

	getLTVPrediction: () =>
		apiRequest('/predictive/ltv-prediction'),

	// Payment Analytics
	getPaymentMethods: (dateRange) =>
		apiRequest('/payments/methods', formatDateRange(dateRange)),

	getPaymentFailures: (dateRange) =>
		apiRequest('/payments/failures', formatDateRange(dateRange)),

	// Executive Overview
	getSummary: (dateRange) =>
		apiRequest('/summary', formatDateRange(dateRange)),

	getRevenueOverview: (dateRange) =>
		apiRequest('/revenue/overview', formatDateRange(dateRange)),

	getMRR: (dateRange) =>
		apiRequest('/mrr', formatDateRange(dateRange)),

	getChurnRate: (dateRange) =>
		apiRequest('/churn/rate', formatDateRange(dateRange)),

	getCustomersRevenue: (dateRange) =>
		apiRequest('/customers/revenue', formatDateRange(dateRange)),

	// Subscription Analytics
	getMRRMomentum: (dateRange) =>
		apiRequest('/mrr/momentum', formatDateRange(dateRange)),

	getCohortRetention: (dateRange) =>
		apiRequest('/subscriptions/cohort-retention', formatDateRange(dateRange)),

	getSubscriptionLifecycle: (dateRange) =>
		apiRequest('/subscriptions/lifecycle', formatDateRange(dateRange)),

	getChurnAnalysis: (dateRange) =>
		apiRequest('/subscriptions/churn-analysis', formatDateRange(dateRange)),

	getPlanMovement: (dateRange) =>
		apiRequest('/subscriptions/plan-movement', formatDateRange(dateRange)),

	getDunningRecovery: (dateRange) =>
		apiRequest('/subscriptions/dunning-recovery', formatDateRange(dateRange)),

	// Revenue Intelligence
	getRevenueBreakdown: (dateRange) =>
		apiRequest('/revenue/breakdown', formatDateRange(dateRange)),

	getRevenueConcentration: (dateRange) =>
		apiRequest('/revenue/concentration', formatDateRange(dateRange)),

	getRevenueByPaymentMethod: (dateRange) =>
		apiRequest('/revenue/by-payment-method', formatDateRange(dateRange)),

	getCohortRevenue: (dateRange) =>
		apiRequest('/revenue/cohort', formatDateRange(dateRange)),

	getRevenueProjections: (dateRange) =>
		apiRequest('/revenue/projections', formatDateRange(dateRange)),

	getFailedPaymentRecovery: (dateRange) =>
		apiRequest('/revenue/failed-payment-recovery', formatDateRange(dateRange)),

	// Churn & Retention
	getChurnComprehensive: (dateRange) =>
		apiRequest('/churn/comprehensive', formatDateRange(dateRange)),

	getChurnMonthlyTrends: (dateRange) =>
		apiRequest('/churn/monthly-trends', formatDateRange(dateRange)),

	getRetentionCohortHeatmap: (maxYears = 6) =>
		apiRequest('/retention/cohort-heatmap', { max_years: maxYears }),

	getRetentionCurve: (dateRange) =>
		apiRequest('/retention/curve', formatDateRange(dateRange)),

	// Used for temporary purpose
	getCohortCustomerCount: (year) =>
		apiRequest('/cohort/customer-count', { year }),
};
