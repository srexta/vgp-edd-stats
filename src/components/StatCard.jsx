import React from 'react';
import { formatCurrency, formatNumber, formatPercentage } from '../utils/api';
import clsx from 'clsx';

function StatCard({
	title,
	value,
	type = 'number',
	change = null,
	subtitle = null,
	loading = false,
	className = '',
}) {
	const formatValue = (val) => {
		if (loading) return '...';
		if (val === null || val === undefined) return '-';

		// If value is already a string (pre-formatted), return as-is
		if (typeof val === 'string') return val;

		switch (type) {
			case 'currency':
				return formatCurrency(val);
			case 'percentage':
				return formatPercentage(val);
			default:
				return formatNumber(val);
		}
	};

	const getChangeColor = (changeValue) => {
		if (changeValue > 0) return 'text-green-600';
		if (changeValue < 0) return 'text-red-600';
		return 'text-gray-600';
	};

	const getChangeIcon = (changeValue) => {
		if (changeValue > 0) return '↑';
		if (changeValue < 0) return '↓';
		return '−';
	};

	return (
		<div className={clsx('stat-card', className)}>
			<div className="flex items-start justify-between">
				<div className="flex-1">
					<p className="text-sm font-medium text-gray-600">{title}</p>
					<p className="text-3xl font-bold text-gray-900 mt-2">
						{formatValue(value)}
					</p>

					{subtitle && (
						<p className="text-sm text-gray-500 mt-1">{subtitle}</p>
					)}

					{change !== null && change !== undefined && (
						<div className={clsx('flex items-center mt-2 text-sm font-medium', getChangeColor(change))}>
							<span className="mr-1">{getChangeIcon(change)}</span>
							<span>{Math.abs(change).toFixed(1)}%</span>
							<span className="text-gray-500 ml-2">vs previous period</span>
						</div>
					)}
				</div>
			</div>

			{loading && (
				<div className="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center rounded-lg">
					<div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div>
				</div>
			)}
		</div>
	);
}

export default StatCard;
