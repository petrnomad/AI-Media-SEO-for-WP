import { createRoot } from 'react-dom/client';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import './queue.css';

/**
 * Queue Component
 *
 * Displays processing queue status and pending jobs.
 */
const Queue = () => {
	const [queueStatus, setQueueStatus] = useState(null);
	const [jobs, setJobs] = useState([]);
	const [loading, setLoading] = useState(true);
	const [autoRefresh, setAutoRefresh] = useState(true);

	useEffect(() => {
		fetchData();

		if (autoRefresh) {
			const interval = setInterval(fetchData, 5000); // Refresh every 5 seconds
			return () => clearInterval(interval);
		}
	}, [autoRefresh]);

	const fetchData = async () => {
		try {
			// Fetch queue status
			const statusResponse = await fetch(window.ajaxurl + '?action=ai_media_queue_status');
			const statusData = await statusResponse.json();
			setQueueStatus(statusData.data);

			// Fetch pending jobs
			const jobsResponse = await apiFetch({
				path: '/ai-media/v1/jobs?status=pending&per_page=20'
			});
			setJobs(jobsResponse.jobs || []);
		} finally {
			setLoading(false);
		}
	};

	const handleCancelAll = async () => {
		if (!confirm(__('Are you sure you want to cancel all pending jobs?', 'ai-media-seo'))) {
			return;
		}

		const formData = new FormData();
		formData.append('action', 'ai_media_cancel_queue');
		formData.append('nonce', window.aiMediaSEO.nonce);

		await fetch(window.ajaxurl, {
			method: 'POST',
			body: formData
		});

		fetchData();
	};

	if (loading) {
		return <div className="ai-media-loading">{__('Loading queue...', 'ai-media-seo')}</div>;
	}

	return (
		<div className="ai-media-queue">
			{/* Queue Status */}
			<div className="ai-media-queue-status-section">
				<div className="ai-media-queue-header">
					<h2>{__('Queue Status', 'ai-media-seo')}</h2>
					<div className="ai-media-queue-controls">
						<label>
							<input
								type="checkbox"
								checked={autoRefresh}
								onChange={(e) => setAutoRefresh(e.target.checked)}
							/>
							{__('Auto-refresh', 'ai-media-seo')}
						</label>
						<button className="button" onClick={fetchData}>
							{__('Refresh', 'ai-media-seo')}
						</button>
						{queueStatus?.pending > 0 && (
							<button className="button button-secondary" onClick={handleCancelAll}>
								{__('Cancel All', 'ai-media-seo')}
							</button>
						)}
					</div>
				</div>

				<div className="ai-media-queue-stats">
					<StatusCard
						title={__('Pending', 'ai-media-seo')}
						value={queueStatus?.pending || 0}
						color="blue"
						icon="⏳"
					/>
					<StatusCard
						title={__('Processing', 'ai-media-seo')}
						value={queueStatus?.in_progress || 0}
						color="green"
						icon="⚡"
						pulse={queueStatus?.in_progress > 0}
					/>
					<StatusCard
						title={__('Failed', 'ai-media-seo')}
						value={queueStatus?.failed || 0}
						color="red"
						icon="❌"
					/>
				</div>
			</div>

			{/* Rate Limits */}
			{queueStatus?.rate_limits && (
				<div className="ai-media-rate-limits">
					<h3>{__('Rate Limits', 'ai-media-seo')}</h3>
					<div className="ai-media-rate-limits-grid">
						{Object.entries(queueStatus.rate_limits).map(([provider, limits]) => (
							<RateLimitCard
								key={provider}
								provider={provider}
								limits={limits}
							/>
						))}
					</div>
				</div>
			)}

			{/* Pending Jobs List */}
			{jobs.length > 0 && (
				<div className="ai-media-pending-jobs">
					<h3>{__('Pending Jobs', 'ai-media-seo')}</h3>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{__('ID', 'ai-media-seo')}</th>
								<th>{__('Attachment', 'ai-media-seo')}</th>
								<th>{__('Language', 'ai-media-seo')}</th>
								<th>{__('Provider', 'ai-media-seo')}</th>
								<th>{__('Status', 'ai-media-seo')}</th>
								<th>{__('Created', 'ai-media-seo')}</th>
								<th>{__('Retries', 'ai-media-seo')}</th>
							</tr>
						</thead>
						<tbody>
							{jobs.map(job => (
								<tr key={job.id}>
									<td>{job.id}</td>
									<td>
										<a href={`post.php?post=${job.attachment_id}&action=edit`}>
											#{job.attachment_id}
										</a>
									</td>
									<td>{job.language_code}</td>
									<td>{job.provider || '-'}</td>
									<td>
										<span className={`ai-media-status ai-media-status-${job.status}`}>
											{job.status}
										</span>
									</td>
									<td>{formatDate(job.created_at)}</td>
									<td>{job.retry_count || 0}</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}

			{jobs.length === 0 && queueStatus?.pending === 0 && (
				<div className="ai-media-empty">
					{__('No jobs in queue. All processing complete!', 'ai-media-seo')}
				</div>
			)}
		</div>
	);
};

/**
 * Status Card Component
 */
const StatusCard = ({ title, value, color, icon, pulse }) => (
	<div className={`ai-media-status-card ai-media-status-card-${color}`}>
		<div className="ai-media-status-card-icon">{icon}</div>
		<div className="ai-media-status-card-content">
			<div className={`ai-media-status-card-value ${pulse ? 'pulse' : ''}`}>
				{value}
			</div>
			<div className="ai-media-status-card-label">{title}</div>
		</div>
	</div>
);

/**
 * Rate Limit Card Component
 */
const RateLimitCard = ({ provider, limits }) => {
	const minuteLimit = limits.minute || {};
	const percentage = (minuteLimit.current / minuteLimit.limit) * 100;

	return (
		<div className="ai-media-rate-limit-card">
			<h4>{provider.toUpperCase()}</h4>
			<div className="ai-media-rate-limit-bar">
				<div
					className="ai-media-rate-limit-fill"
					style={{ width: `${percentage}%` }}
				/>
			</div>
			<div className="ai-media-rate-limit-text">
				{minuteLimit.current}/{minuteLimit.limit} requests/min
				<span className="ai-media-rate-limit-remaining">
					{minuteLimit.remaining} remaining
				</span>
			</div>
			{minuteLimit.delay > 0 && (
				<div className="ai-media-rate-limit-delay">
					{__('Delay:', 'ai-media-seo')} {minuteLimit.delay}s
				</div>
			)}
		</div>
	);
};

/**
 * Format date helper
 */
const formatDate = (dateString) => {
	if (!dateString) return '-';
	const date = new Date(dateString);
	return date.toLocaleString();
};

// Initialize
const container = document.getElementById('ai-media-queue-root');
if (container) {
	const root = createRoot(container);
	root.render(<Queue />);
}
