import React, { useState } from 'react';
import ExecutiveOverviewPage from './pages/ExecutiveOverviewPage';
import CustomerAnalyticsPage from './pages/CustomerAnalyticsPage';
import RevenueIntelligencePage from './pages/RevenueIntelligencePage';
import ProductPerformancePage from './pages/ProductPerformancePage';
import SubscriptionAnalyticsPage from './pages/SubscriptionAnalyticsPage';
import CustomersRevenuePage from './pages/CustomersRevenuePage';
import MRRGrowthPage from './pages/MRRGrowthPage';
import ChurnRetentionPage from './pages/ChurnRetentionPage';
import RenewalsPage from './pages/RenewalsPage';
import RefundsPage from './pages/RefundsPage';
import LicensingPage from './pages/LicensingPage';
import SitesPage from './pages/SitesPage';
import SupportPage from './pages/SupportPage';
import DateRangeFilter from './components/DateRangeFilter';
import { subDays, subMonths } from 'date-fns';

function App({ section }) {
	// Global date range state
	const [dateRange, setDateRange] = useState({
		startDate: subMonths(new Date(), 12),
		endDate: new Date(),
		preset: '365',
	});

	// Render appropriate page based on section
	const renderPage = () => {
		switch (section) {
			case 'executive-overview':
				return <ExecutiveOverviewPage dateRange={dateRange} />;
			case 'customer-analytics':
				return <CustomerAnalyticsPage dateRange={dateRange} />;
			case 'revenue-intelligence':
				return <RevenueIntelligencePage dateRange={dateRange} />;
			case 'product-performance':
				return <ProductPerformancePage dateRange={dateRange} />;
			case 'subscription-analytics':
				return <SubscriptionAnalyticsPage dateRange={dateRange} />;
			case 'customers-revenue':
				return <CustomersRevenuePage dateRange={dateRange} />;
			case 'mrr-growth':
				return <MRRGrowthPage dateRange={dateRange} />;
			case 'churn-retention':
				return <ChurnRetentionPage dateRange={dateRange} />;
			case 'renewals':
				return <RenewalsPage dateRange={dateRange} />;
			case 'refunds':
				return <RefundsPage dateRange={dateRange} />;
			case 'licensing':
				return <LicensingPage dateRange={dateRange} />;
			case 'sites':
				return <SitesPage dateRange={dateRange} />;
			case 'support':
				return <SupportPage dateRange={dateRange} />;
			default:
				return <ExecutiveOverviewPage dateRange={dateRange} />;
		}
	};

	return (
		<div className="vgp-edd-stats-app">
			{/* Global controls */}
			<div className="mb-6 flex items-center justify-between bg-white p-4 rounded-lg shadow-sm border border-gray-200">
				<div>
					<h2 className="text-xl font-semibold text-gray-900">
						{getSectionTitle(section)}
					</h2>
					<p className="text-sm text-gray-500 mt-1">
						Analytics and insights for your EDD store
					</p>
				</div>
				<DateRangeFilter
					dateRange={dateRange}
					onChange={setDateRange}
				/>
			</div>

			{/* Page content */}
			<div className="space-y-6">
				{renderPage()}
			</div>
		</div>
	);
}

function getSectionTitle(section) {
	const titles = {
		'executive-overview': 'Executive Overview',
		'customer-analytics': 'Customer Analytics',
		'revenue-intelligence': 'Revenue Intelligence',
		'product-performance': 'Product Performance',
		'subscription-analytics': 'Subscription Analytics',
		'customers-revenue': 'Customers & Revenue',
		'mrr-growth': 'MRR & Growth',
		'churn-retention': 'Churn & Retention',
		'renewals': 'Renewals & Cancellations',
		'refunds': 'Refunds',
		'licensing': 'Software Licensing',
		'sites': 'Sites Stats',
		'support': 'Support',
	};

	return titles[section] || 'Dashboard';
}

export default App;
