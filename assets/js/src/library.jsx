import { createRoot } from 'react-dom/client';
import { useState, useEffect, useCallback } from 'react';
import { FixedSizeList as List } from 'react-window';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import './library.css';

/**
 * Media Library Component
 *
 * Displays images with AI-generated metadata for review.
 */
const MediaLibrary = () => {
	const [jobs, setJobs] = useState([]);
	const [loading, setLoading] = useState(true);
	const [filters, setFilters] = useState({
		status: 'needs_review',
		language: 'en',
		page: 1,
		per_page: 50
	});
	const [selectedJobs, setSelectedJobs] = useState(new Set());
	const [processing, setProcessing] = useState(false);

	useEffect(() => {
		fetchJobs();
	}, [filters]);

	const fetchJobs = async () => {
		setLoading(true);
		try {
			const response = await apiFetch({
				path: `/ai-media/v1/jobs?${new URLSearchParams(filters)}`
			});
			setJobs(response.jobs || []);
		} finally {
			setLoading(false);
		}
	};

	const handleFilterChange = (key, value) => {
		setFilters(prev => ({ ...prev, [key]: value, page: 1 }));
	};

	const toggleSelection = (jobId) => {
		setSelectedJobs(prev => {
			const newSet = new Set(prev);
			if (newSet.has(jobId)) {
				newSet.delete(jobId);
			} else {
				newSet.add(jobId);
			}
			return newSet;
		});
	};

	const selectAll = () => {
		setSelectedJobs(new Set(jobs.map(j => j.id)));
	};

	const deselectAll = () => {
		setSelectedJobs(new Set());
	};

	const handleBulkApprove = async () => {
		if (selectedJobs.size === 0) return;

		setProcessing(true);
		const promises = Array.from(selectedJobs).map(jobId =>
			apiFetch({
				path: '/ai-media/v1/approve',
				method: 'POST',
				data: { job_id: jobId }
			}).catch(() => {})
		);

		await Promise.all(promises);
		setProcessing(false);
		setSelectedJobs(new Set());
		fetchJobs();
	};

	const Row = ({ index, style }) => {
		const job = jobs[index];
		if (!job) return null;

		const metadata = job.response_data || {};
		const isSelected = selectedJobs.has(job.id);

		return (
			<div style={style} className="ai-media-row">
				<div className="ai-media-row-inner">
					<input
						type="checkbox"
						checked={isSelected}
						onChange={() => toggleSelection(job.id)}
					/>
					<div className="ai-media-thumbnail">
						<img
							src={`/wp-content/uploads/${job.attachment_id}-150x150.jpg`}
							alt=""
							onError={(e) => e.target.style.display = 'none'}
						/>
					</div>
					<div className="ai-media-details">
						<div className="ai-media-field">
							<strong>{__('ALT:', 'ai-media-seo')}</strong>
							<span>{metadata.alt || '-'}</span>
						</div>
						{metadata.caption && (
							<div className="ai-media-field">
								<strong>{__('Caption:', 'ai-media-seo')}</strong>
								<span>{metadata.caption}</span>
							</div>
						)}
						<div className="ai-media-meta">
							<span className={`ai-media-score ai-media-score-${getScoreColor(job.score)}`}>
								{__('Score:', 'ai-media-seo')} {(job.score * 100).toFixed(0)}%
							</span>
							<span className="ai-media-language">{job.language_code}</span>
						</div>
					</div>
					<div className="ai-media-actions">
						<button
							className="button button-small button-primary"
							onClick={() => handleApprove(job.id)}
							disabled={processing}
						>
							{__('Approve', 'ai-media-seo')}
						</button>
						<button
							className="button button-small"
							onClick={() => handleRegenerate(job.attachment_id)}
							disabled={processing}
						>
							{__('Regenerate', 'ai-media-seo')}
						</button>
					</div>
				</div>
			</div>
		);
	};

	const handleApprove = async (jobId) => {
		setProcessing(true);
		try {
			await apiFetch({
				path: '/ai-media/v1/approve',
				method: 'POST',
				data: { job_id: jobId }
			});
			fetchJobs();
		} finally {
			setProcessing(false);
		}
	};

	const handleRegenerate = async (attachmentId) => {
		setProcessing(true);
		try {
			await apiFetch({
				path: '/ai-media/v1/regenerate',
				method: 'POST',
				data: {
					attachment_id: attachmentId,
					language: filters.language
				}
			});
			fetchJobs();
		} finally {
			setProcessing(false);
		}
	};

	return (
		<div className="ai-media-library">
			{/* Filters */}
			<div className="ai-media-filters">
				<select
					value={filters.status}
					onChange={(e) => handleFilterChange('status', e.target.value)}
				>
					<option value="needs_review">{__('Needs Review', 'ai-media-seo')}</option>
					<option value="approved">{__('Approved', 'ai-media-seo')}</option>
					<option value="failed">{__('Failed', 'ai-media-seo')}</option>
					<option value="pending">{__('Pending', 'ai-media-seo')}</option>
				</select>

				<select
					value={filters.language}
					onChange={(e) => handleFilterChange('language', e.target.value)}
				>
					<option value="en">English</option>
					<option value="cs">Čeština</option>
					<option value="de">Deutsch</option>
					<option value="sk">Slovenčina</option>
				</select>

				<div className="ai-media-filter-actions">
					{selectedJobs.size > 0 && (
						<>
							<button
								className="button button-primary"
								onClick={handleBulkApprove}
								disabled={processing}
							>
								{__('Approve Selected', 'ai-media-seo')} ({selectedJobs.size})
							</button>
							<button className="button" onClick={deselectAll}>
								{__('Deselect All', 'ai-media-seo')}
							</button>
						</>
					)}
					{selectedJobs.size === 0 && jobs.length > 0 && (
						<button className="button" onClick={selectAll}>
							{__('Select All', 'ai-media-seo')}
						</button>
					)}
				</div>
			</div>

			{/* Virtual List */}
			{loading ? (
				<div className="ai-media-loading">{__('Loading...', 'ai-media-seo')}</div>
			) : jobs.length === 0 ? (
				<div className="ai-media-empty">
					{__('No jobs found with current filters.', 'ai-media-seo')}
				</div>
			) : (
				<List
					height={600}
					itemCount={jobs.length}
					itemSize={120}
					width="100%"
				>
					{Row}
				</List>
			)}
		</div>
	);
};

/**
 * Get score color class
 */
const getScoreColor = (score) => {
	if (score >= 0.8) return 'high';
	if (score >= 0.6) return 'medium';
	return 'low';
};

// Initialize
const container = document.getElementById('ai-media-library-root');
if (container) {
	const root = createRoot(container);
	root.render(<MediaLibrary />);
}
