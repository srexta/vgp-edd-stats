import React, { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { API, formatCurrency } from '../utils/api';
import StatCard from '../components/StatCard';
import ChartWrapper from '../components/ChartWrapper';

function ChurnRetentionPage({ dateRange }) {
	const [maxYears, setMaxYears] = useState(10);
	const [selectedCohortYear, setSelectedCohortYear] = useState(null);

	// Fetch comprehensive churn data
	const { data: churnData, isLoading: churnLoading } = useQuery({
		queryKey: ['churn-comprehensive', dateRange],
		queryFn: () => API.getChurnComprehensive(dateRange),
	});

	// Fetch monthly trends
	const { data: trendsData, isLoading: trendsLoading } = useQuery({
		queryKey: ['churn-monthly-trends', dateRange],
		queryFn: () => API.getChurnMonthlyTrends(dateRange),
	});

	// Fetch cohort heatmap
	const { data: cohortData, isLoading: cohortLoading } = useQuery({
		queryKey: ['retention-cohort-heatmap', maxYears],
		queryFn: () => API.getRetentionCohortHeatmap(maxYears),
	});

	// Fetch retention curve
	const { data: curveData, isLoading: curveLoading } = useQuery({
		queryKey: ['retention-curve', dateRange],
		queryFn: () => API.getRetentionCurve(dateRange),
	});

	// Get available cohort years
	const cohortYears = cohortData?.data?.cohorts?.map(c => c.signup_year).sort() || [];
	
	// Set initial selected year when cohort data loads
	useEffect(() => {
		if (!selectedCohortYear && cohortYears.length > 0) {
			setSelectedCohortYear(cohortYears[0]);
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [cohortYears]);

	// Fetch cohort customer details for selected year
	const { data: cohortDetailsData, isLoading: cohortDetailsLoading, error: cohortDetailsError } = useQuery({
		queryKey: ['cohort-customer-details', selectedCohortYear],
		queryFn: () => API.getCohortCustomerDetails(selectedCohortYear),
		enabled: !!selectedCohortYear,
	});

	// Debug logging
	useEffect(() => {
		console.log('=== COHORT DETAILS DEBUG ===');
		console.log('Selected Year:', selectedCohortYear);
		console.log('Loading:', cohortDetailsLoading);
		console.log('Error:', cohortDetailsError);
		console.log('Raw Data:', cohortDetailsData);
		
		if (cohortDetailsData) {
			console.log('Data Structure:', {
				hasData: !!cohortDetailsData,
				subscriptions: cohortDetailsData?.subscriptions,
				subscriptionsLength: cohortDetailsData?.subscriptions?.length,
				total: cohortDetailsData?.total,
			});
			
			// Log first subscription details for debugging
			if (cohortDetailsData?.subscriptions?.length > 0) {
				const firstSub = cohortDetailsData.subscriptions[0];
				console.log('First Subscription Sample:', {
					subscription_id: firstSub.subscription_id,
					name: firstSub.name,
					recurring_payments: firstSub.recurring_payments,
					recurring_payments_display: firstSub.recurring_payments_display,
					payment_history: firstSub.payment_history,
					payment_history_length: firstSub.payment_history?.length,
				});
			}
		}
		console.log('=== END DEBUG ===');
	}, [selectedCohortYear, cohortDetailsLoading, cohortDetailsError, cohortDetailsData]);

	// Extract summary data
	const summary = churnData?.data?.summary || {};
	const monthlyTrend = churnData?.data?.monthly_trend || [];
	const churnByReason = churnData?.data?.churn_by_reason || [];

	// Churn Trend Chart
	const churnTrendOption = {
		tooltip: {
			trigger: 'axis',
			formatter: (params) => {
				let result = `${params[0].name}<br/>`;
				params.forEach((param) => {
					if (param.seriesName === 'Churn Rate') {
						result += `${param.marker} ${param.seriesName}: ${param.value}%<br/>`;
					} else {
						result += `${param.marker} ${param.seriesName}: ${param.value}<br/>`;
					}
				});
				return result;
			},
		},
		legend: {
			data: ['Churned', 'Canceled', 'Expired', 'Churn Rate'],
			bottom: 0,
		},
		grid: {
			left: '3%',
			right: '4%',
			bottom: '15%',
			containLabel: true,
		},
		xAxis: {
			type: 'category',
			data: trendsData?.data?.trends?.map((d) => d.label) || [],
			axisLabel: {
				rotate: 45,
			},
		},
		yAxis: [
			{
				type: 'value',
				name: 'Count',
				position: 'left',
			},
			{
				type: 'value',
				name: 'Rate (%)',
				position: 'right',
				axisLabel: {
					formatter: '{value}%',
				},
			},
		],
		series: [
			{
				name: 'Churned',
				type: 'bar',
				stack: 'total',
				data: trendsData?.data?.trends?.map((d) => d.churned) || [],
				itemStyle: { color: '#ef4444' },
			},
			{
				name: 'Canceled',
				type: 'bar',
				stack: 'detail',
				data: trendsData?.data?.trends?.map((d) => d.canceled) || [],
				itemStyle: { color: '#f97316' },
				emphasis: { focus: 'series' },
			},
			{
				name: 'Expired',
				type: 'bar',
				stack: 'detail',
				data: trendsData?.data?.trends?.map((d) => d.expired) || [],
				itemStyle: { color: '#eab308' },
				emphasis: { focus: 'series' },
			},
			{
				name: 'Churn Rate',
				type: 'line',
				yAxisIndex: 1,
				data: trendsData?.data?.trends?.map((d) => d.churn_rate) || [],
				itemStyle: { color: '#8b5cf6' },
				smooth: true,
			},
		],
	};

	// Churn by Reason Pie Chart
	const churnReasonOption = {
		tooltip: {
			trigger: 'item',
			formatter: '{a} <br/>{b}: {c} ({d}%)',
		},
		legend: {
			orient: 'vertical',
			left: 'left',
		},
		series: [
			{
				name: 'Churn Reason',
				type: 'pie',
				radius: ['40%', '70%'],
				avoidLabelOverlap: false,
				itemStyle: {
					borderRadius: 10,
					borderColor: '#fff',
					borderWidth: 2,
				},
				label: {
					show: true,
					formatter: '{b}: {d}%',
				},
				emphasis: {
					label: {
						show: true,
						fontSize: 16,
						fontWeight: 'bold',
					},
				},
				data: churnByReason.map((item, index) => ({
					value: item.count,
					name: item.reason,
					itemStyle: {
						color: index === 0 ? '#f97316' : '#eab308',
					},
				})),
			},
		],
	};

	// Retention Curve Chart
	const retentionCurveOption = {
		tooltip: {
			trigger: 'axis',
			formatter: (params) => {
				const p = params[0];
				return `${p.name}<br/>
					${p.marker} Retention: ${p.value}%<br/>
					Retained: ${curveData?.data?.curve?.[p.dataIndex]?.retained || 0}`;
			},
		},
		xAxis: {
			type: 'category',
			data: curveData?.data?.curve?.map((d) => d.label) || [],
			axisLabel: {
				rotate: 45,
			},
		},
		yAxis: {
			type: 'value',
			name: 'Retention %',
			max: 100,
			axisLabel: {
				formatter: '{value}%',
			},
		},
		series: [
			{
				name: 'Retention Rate',
				type: 'line',
				data: curveData?.data?.curve?.map((d) => d.retention_rate) || [],
				smooth: true,
				itemStyle: { color: '#10b981' },
				areaStyle: {
					color: {
						type: 'linear',
						x: 0,
						y: 0,
						x2: 0,
						y2: 1,
						colorStops: [
							{ offset: 0, color: 'rgba(16, 185, 129, 0.3)' },
							{ offset: 1, color: 'rgba(16, 185, 129, 0.05)' },
						],
					},
				},
				markLine: {
					data: [
						{ type: 'average', name: 'Average' },
					],
				},
			},
		],
	};

	// Get churn rate color
	const getChurnRateColor = (rate) => {
		if (rate < 30) return 'bg-green-100 text-green-800';
		if (rate < 60) return 'bg-yellow-100 text-yellow-800';
		return 'bg-red-100 text-red-800';
	};

	// Get churn rate cell color for heatmap (for other uses)
	const getCellColor = (rate) => {
		if (rate === null || rate === undefined) return 'bg-gray-100 text-gray-400';
		if (rate < 20) return 'bg-green-200 text-green-900';
		if (rate < 40) return 'bg-green-100 text-green-800';
		if (rate < 60) return 'bg-yellow-100 text-yellow-800';
		if (rate < 80) return 'bg-orange-100 text-orange-800';
		return 'bg-red-100 text-red-800';
	};

	// Get retention rate cell color for cohort table (inverted logic: high retention = good)
	const getRetentionCellColor = (retentionRate) => {
		if (retentionRate === null || retentionRate === undefined) return 'bg-gray-100 text-gray-400';
		// High retention (80%+) = excellent (green)
		if (retentionRate >= 80) return 'bg-green-200 text-green-900';
		// Good retention (60-80%) = good (light green)
		if (retentionRate >= 60) return 'bg-green-100 text-green-800';
		// Medium retention (40-60%) = moderate (yellow)
		if (retentionRate >= 40) return 'bg-yellow-100 text-yellow-800';
		// Low retention (20-40%) = concerning (orange)
		if (retentionRate >= 20) return 'bg-orange-100 text-orange-800';
		// Very low retention (<20%) = critical (red)
		return 'bg-red-100 text-red-800';
	};

	return (
		<div className="space-y-6">
			{/* Summary Cards */}
			<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
				<StatCard
					title="Current Churn Rate"
					value={summary.churn_rate || 0}
					type="percentage"
					subtitle={`${summary.total_churned || 0} churned out of ${summary.active_at_start || 0}`}
					loading={churnLoading}
					change={summary.churn_change_percent}
				/>
				<StatCard
					title="Retention Rate"
					value={summary.retention_rate || 0}
					type="percentage"
					subtitle="Percentage of customers retained"
					loading={churnLoading}
				/>
				<StatCard
					title="Annualized Churn"
					value={summary.annualized_churn || 0}
					type="percentage"
					subtitle="Projected annual churn rate"
					loading={churnLoading}
				/>
				<StatCard
					title="Avg. Lifetime"
					value={`${curveData?.data?.average_lifetime || 0} mo`}
					subtitle="Average subscription lifetime"
					loading={curveLoading}
				/>
			</div>

			{/* Churn Breakdown Cards */}
			<div className="grid grid-cols-1 md:grid-cols-3 gap-6">
				<StatCard
					title="Voluntary Churn"
					value={summary.canceled || 0}
					description="Customers who canceled"
					loading={churnLoading}
					icon="user-minus"
				/>
				<StatCard
					title="Involuntary Churn"
					value={summary.expired || 0}
					description="Subscriptions expired/failed"
					loading={churnLoading}
					icon="clock"
				/>
				<StatCard
					title="Current Active"
					value={summary.current_active || 0}
					description="Currently active subscriptions"
					loading={churnLoading}
					valueColor="text-green-600"
					icon="users"
				/>
			</div>

			{/* Monthly Churn Trends Chart */}
			<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
				<h3 className="text-lg font-semibold mb-4">Monthly Churn Trends</h3>
				<ChartWrapper
					option={churnTrendOption}
					loading={trendsLoading}
					height={400}
				/>
			</div>

			{/* Churn Reason & Retention Curve Row */}
			<div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
				{/* Churn by Reason */}
				<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
					<h3 className="text-lg font-semibold mb-4">Churn by Reason</h3>
					<ChartWrapper
						option={churnReasonOption}
						loading={churnLoading}
						height={300}
					/>
				</div>

				{/* Retention Curve */}
				<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
					<h3 className="text-lg font-semibold mb-4">Retention Curve</h3>
					<ChartWrapper
						option={retentionCurveOption}
						loading={curveLoading}
						height={300}
					/>
				</div>
			</div>

			{/* Cohort Retention Heatmap */}
			<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
				<div className="flex items-center justify-between mb-4">
					<h3 className="text-lg font-semibold">Cohort Retention Analysis</h3>
					<div className="flex items-center gap-2">
						<label className="text-sm text-gray-600">Max Years:</label>
						<select
							value={maxYears}
							onChange={(e) => setMaxYears(parseInt(e.target.value))}
							className="border border-gray-300 rounded px-2 py-1 text-sm"
						>
							{[3, 4, 5, 6, 7, 8].map((n) => (
								<option key={n} value={n}>{n}</option>
							))}
						</select>
					</div>
				</div>

				{cohortLoading ? (
					<div className="h-64 flex items-center justify-center">
						<div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
					</div>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200">
							<thead className="bg-gray-50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
										Signup Year
									</th>
									{/* <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
										Customers
									</th> */}
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
										Subscriptions
									</th>
									{Array.from({ length: maxYears }, (_, i) => (
										<th
											key={i}
											className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider"
										>
											Year {i + 1} Retention
										</th>
									))}
								</tr>
							</thead>
							<tbody className="bg-white divide-y divide-gray-200">
								{cohortData?.data?.cohorts?.map((cohort) => (
									<tr key={cohort.signup_year} className="hover:bg-gray-50">
										<td className="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
											{cohort.signup_year}
										</td>
										{/* <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
											{cohort.customers}
										</td> */}
										<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
											{cohort.subscriptions || cohort.customers}
										</td>
										{Array.from({ length: maxYears }, (_, i) => {
											const churnRate = cohort.churn_rates?.[`year_${i + 1}`];
											// Convert churn rate to retention rate
											const retentionRate = churnRate !== null && churnRate !== undefined 
												? Math.round((100 - churnRate) * 10) / 10 
												: null;
											return (
												<td
													key={i}
													className={`px-4 py-3 whitespace-nowrap text-sm text-center ${getRetentionCellColor(retentionRate)}`}
												>
													{retentionRate !== null && retentionRate !== undefined ? `${retentionRate}%` : '-'}
												</td>
											);
										})}
									</tr>
								))}
							</tbody>
						</table>
					</div>
				)}

				{/* Legend */}
				<div className="mt-4 flex items-center gap-4 text-xs">
					<span className="font-medium text-gray-700">Retention Rate:</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-green-200 rounded"></span> Excellent (≥80%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-green-100 rounded"></span> Good (60-80%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-yellow-100 rounded"></span> Moderate (40-60%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-orange-100 rounded"></span> Concerning (20-40%)
					</span>
					<span className="flex items-center gap-1">
						<span className="w-4 h-4 bg-red-100 rounded"></span> Critical (&lt;20%)
					</span>
				</div>
			</div>


			{/* Cohort Customer Details Section */}
			<div className="bg-white p-6 rounded-lg shadow-sm border border-gray-200">
				<div className="flex items-center justify-between mb-4">
					<h3 className="text-lg font-semibold">Cohort Customer Details</h3>
					<button
						onClick={() => {
							// Clear cache and refetch
							fetch(window.vgpEddStats?.apiUrl + '/cache/clear', {
								method: 'POST',
								headers: {
									'X-WP-Nonce': window.vgpEddStats?.nonce,
								},
							}).then(() => {
								window.location.reload();
							});
						}}
						className="px-3 py-1 text-sm bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-md transition-colors"
						title="Clear cache and reload data"
					>
						Clear Cache & Reload
					</button>
				</div>

				{/* Year Selection Tabs */}
				{cohortYears.length > 0 && (
					<div className="mb-4 flex items-center gap-2 flex-wrap border-b border-gray-200 pb-2">
						{cohortYears.map((year) => (
							<button
								key={year}
								onClick={() => setSelectedCohortYear(year)}
								className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${
									selectedCohortYear === year
										? 'bg-blue-600 text-white'
										: 'bg-gray-100 text-gray-700 hover:bg-gray-200'
								}`}
							>
								{year}
							</button>
						))}
					</div>
				)}

				{/* Cohort Summary */}
				{selectedCohortYear && (
					<div className="mb-4">
						<p className="text-sm text-gray-600">
							<strong>{selectedCohortYear} Cohort</strong> ({cohortDetailsData?.total || 0} subscription{cohortDetailsData?.total !== 1 ? 's' : ''}):
						</p>
						{cohortDetailsData?.total > 0 && (
							<p className="text-xs text-gray-500 mt-1">
								Showing all subscriptions created in {selectedCohortYear} with their complete payment history and current status.
							</p>
						)}
					</div>
				)}

				{/* Customer Details Table */}
				{cohortDetailsLoading ? (
					<div className="h-64 flex items-center justify-center">
						<div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
					</div>
				) : cohortDetailsError ? (
					<div className="h-64 flex items-center justify-center">
						<div className="text-center">
							<p className="text-red-600 font-medium mb-2">Error loading subscription data</p>
							<p className="text-sm text-gray-600">{cohortDetailsError.message || 'Please try refreshing the page or clearing the cache.'}</p>
						</div>
					</div>
				) : (
					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200">
							<thead className="bg-gray-50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subscription ID</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recurring Amount</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Payment Date</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Payment</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recurring Payments</th>
									<th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment History</th>
								</tr>
							</thead>
							<tbody className="bg-white divide-y divide-gray-200">
								{cohortDetailsData?.subscriptions?.length > 0 ? (
									cohortDetailsData.subscriptions.map((sub, idx) => (
										<tr key={idx} className="hover:bg-gray-50">
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{sub.name}</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{sub.email}</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">{sub.subscription_id}</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm">
												<span className={`px-2 py-1 rounded text-xs font-medium ${
													sub.status === 'active' ? 'bg-green-100 text-green-800' :
													sub.status === 'completed' ? 'bg-blue-100 text-blue-800' :
													sub.status === 'cancelled' ? 'bg-red-100 text-red-800' :
													sub.status === 'expired' ? 'bg-orange-100 text-orange-800' :
													'bg-gray-100 text-gray-800'
												}`}>
													{sub.status}
												</span>
											</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
												{formatCurrency(sub.recurring_amount || 0)}
											</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
												{sub.last_payment_date 
													? new Date(sub.last_payment_date).toLocaleString('en-US', {
														year: 'numeric',
														month: '2-digit',
														day: '2-digit',
														hour: '2-digit',
														minute: '2-digit',
														second: '2-digit'
													})
													: 'No payments found'}
											</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
												{sub.last_payment ? formatCurrency(sub.last_payment) : 'N/A'}
											</td>
											<td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
												{sub.recurring_payments_display || `${sub.recurring_payments || 0}x`}
											</td>
											<td className="px-4 py-3 text-sm text-gray-600">
												{sub.payment_history && sub.payment_history.length > 0 ? (
													<div className="space-y-1">
														<div className="text-xs font-medium text-gray-700 mb-1">Payment History</div>
														{sub.payment_history.map((payment, pIdx) => (
															<div key={pIdx} className="text-xs flex items-center gap-2">
																{payment.admin_url ? (
																	<a 
																		href={payment.admin_url}
																		target="_blank"
																		rel="noopener noreferrer"
																		className="text-blue-600 hover:text-blue-800 hover:underline"
																	>
																		#{payment.id}
																	</a>
																) : (
																	<span className="text-gray-600">#{payment.id}</span>
																)}
																<span className="text-gray-500">
																	{new Date(payment.date).toLocaleDateString('en-US', { 
																		month: 'short', 
																		year: 'numeric' 
																	})}
																</span>
																<span className="text-green-600 font-medium">
																	{formatCurrency(payment.amount)}
																</span>
															</div>
														))}
													</div>
												) : (
													<span className="text-gray-400">No payments</span>
												)}
											</td>
										</tr>
									))
							) : (
								<tr>
									<td colSpan="9" className="px-4 py-8 text-center text-gray-500">
										{cohortDetailsData?.message || 'No subscription data found for this cohort year.'}
									</td>
								</tr>
							)}
							</tbody>
						</table>
					</div>
				)}
			</div>
		</div>
	);
}

export default ChurnRetentionPage;

