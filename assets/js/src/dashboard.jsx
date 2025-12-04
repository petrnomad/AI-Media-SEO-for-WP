import { createRoot } from 'react-dom/client';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import './dashboard.css';

/**
 * Dashboard Component
 *
 * Displays overview statistics and quick actions.
 */
const Dashboard = () => {
	const [stats, setStats] = useState(null);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState(null);

	useEffect(() => {
		fetchStats();
		// Refresh every 30 seconds
		const interval = setInterval(fetchStats, 30000);
		return () => clearInterval(interval);
	}, []);

	const fetchStats = async () => {
		try {
			const data = await apiFetch({
				path: '/ai-media/v1/stats'
			});
			setStats(data);
			setError(null);
		} catch (err) {
			setError(err.message);
		} finally {
			setLoading(false);
		}
	};

	if (loading) {
		return <div className="ai-media-loading">{__('Loading...', 'ai-media-seo')}</div>;
	}

	if (error) {
		return <div className="notice notice-error"><p>{error}</p></div>;
	}

	const todayStats = stats?.today || {};
	const allStats = stats?.all || {};

	return (
		<div className="ai-media-dashboard">
			<div className="ai-media-dashboard-grid">
				{/* Today Stats */}
				<div className="ai-media-widget">
					<h3>{__('Today', 'ai-media-seo')}</h3>
					<div className="ai-media-stats">
						<StatCard
							label={__('Processed', 'ai-media-seo')}
							value={todayStats.approved || 0}
							color="green"
						/>
						<StatCard
							label={__('Pending', 'ai-media-seo')}
							value={todayStats.pending || 0}
							color="blue"
						/>
						<StatCard
							label={__('Failed', 'ai-media-seo')}
							value={todayStats.failed || 0}
							color="red"
						/>
					</div>
				</div>

				{/* All Time Stats */}
				<div className="ai-media-widget">
					<h3>{__('All Time', 'ai-media-seo')}</h3>
					<div className="ai-media-stats">
						<StatCard
							label={__('Total Processed', 'ai-media-seo')}
							value={allStats.approved || 0}
							color="green"
						/>
						<StatCard
							label={__('Avg Score', 'ai-media-seo')}
							value={((allStats.avg_score || 0) * 100).toFixed(0) + '%'}
							color="blue"
						/>
						<StatCard
							label={__('Total Cost', 'ai-media-seo')}
							value={'$' + ((allStats.total_cost || 0) / 100).toFixed(2)}
							color="gray"
						/>
					</div>
				</div>

				{/* Quick Actions */}
				<div className="ai-media-widget">
					<h3>{__('Quick Actions', 'ai-media-seo')}</h3>
					<div className="ai-media-actions">
						<a href="admin.php?page=ai-media-library" className="button button-primary">
							{__('Process Images', 'ai-media-seo')}
						</a>
						<a href="admin.php?page=ai-media-queue" className="button">
							{__('View Queue', 'ai-media-seo')}
						</a>
						<a href="admin.php?page=ai-media-settings" className="button">
							{__('Settings', 'ai-media-seo')}
						</a>
					</div>
				</div>

				{/* Queue Status */}
				<div className="ai-media-widget">
					<h3>{__('Queue Status', 'ai-media-seo')}</h3>
					<div className="ai-media-queue-status">
						<StatusRow
							label={__('Pending', 'ai-media-seo')}
							value={todayStats.pending || 0}
						/>
						<StatusRow
							label={__('Processing', 'ai-media-seo')}
							value={todayStats.processing || 0}
						/>
						<StatusRow
							label={__('Needs Review', 'ai-media-seo')}
							value={todayStats.needs_review || 0}
						/>
					</div>
				</div>
			</div>
		</div>
	);
};

/**
 * Stat Card Component
 */
const StatCard = ({ label, value, color = 'blue' }) => (
	<div className="ai-media-stat">
		<div className={`ai-media-stat-value ai-media-stat-${color}`}>
			{value}
		</div>
		<div className="ai-media-stat-label">{label}</div>
	</div>
);

/**
 * Status Row Component
 */
const StatusRow = ({ label, value }) => (
	<div className="ai-media-status-row">
		<span className="ai-media-status-label">{label}</span>
		<span className="ai-media-status-value">{value}</span>
	</div>
);

// Initialize
const container = document.getElementById('ai-media-dashboard-root');
if (container) {
	const root = createRoot(container);
	root.render(<Dashboard />);
}
