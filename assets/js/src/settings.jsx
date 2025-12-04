import { createRoot } from 'react-dom/client';
import { useState, useEffect } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import './settings.css';

/**
 * Settings Component
 *
 * Plugin settings page.
 */
const Settings = () => {
	const [settings, setSettings] = useState({});
	const [providers, setProviders] = useState({});
	const [loading, setLoading] = useState(true);
	const [saving, setSaving] = useState(false);
	const [message, setMessage] = useState(null);

	useEffect(() => {
		loadSettings();
	}, []);

	const loadSettings = async () => {
		setLoading(true);
		// In real implementation, these would come from WP REST API
		// For now, using inline data from window.aiMediaSEO.settings
		const data = window.aiMediaSEO?.settings || {};
		setSettings(data);
		setProviders(window.aiMediaSEO?.providers || {});
		setLoading(false);
	};

	const handleSave = async (e) => {
		e.preventDefault();
		setSaving(true);

		try {
			// Save settings via AJAX
			const formData = new FormData();
			formData.append('action', 'ai_media_save_settings');
			formData.append('nonce', window.aiMediaSEO.nonce);
			formData.append('settings', JSON.stringify(settings));
			formData.append('providers', JSON.stringify(providers));

			const response = await fetch(window.ajaxurl, {
				method: 'POST',
				body: formData
			});

			const result = await response.json();

			if (result.success) {
				setMessage({ type: 'success', text: __('Settings saved successfully.', 'ai-media-seo') });
			} else {
				setMessage({ type: 'error', text: result.data || __('Failed to save settings.', 'ai-media-seo') });
			}
		} catch (error) {
			setMessage({ type: 'error', text: error.message });
		} finally {
			setSaving(false);
			setTimeout(() => setMessage(null), 5000);
		}
	};

	const updateSetting = (key, value) => {
		setSettings(prev => ({ ...prev, [key]: value }));
	};

	const updateProvider = (provider, key, value) => {
		setProviders(prev => ({
			...prev,
			[provider]: { ...prev[provider], [key]: value }
		}));
	};

	if (loading) {
		return <div className="ai-media-loading">{__('Loading...', 'ai-media-seo')}</div>;
	}

	return (
		<div className="ai-media-settings">
			{message && (
				<div className={`notice notice-${message.type} is-dismissible`}>
					<p>{message.text}</p>
				</div>
			)}

			<form onSubmit={handleSave}>
				{/* Providers Section */}
				<div className="ai-media-settings-section">
					<h2>{__('AI Providers', 'ai-media-seo')}</h2>

					<div className="ai-media-form-field">
						<label>{__('OpenAI API Key', 'ai-media-seo')}</label>
						<input
							type="password"
							value={providers.openai?.api_key || ''}
							onChange={(e) => updateProvider('openai', 'api_key', e.target.value)}
							placeholder="sk-..."
						/>
						<p className="description">
							{__('Get your API key from', 'ai-media-seo')}{' '}
							<a href="https://platform.openai.com/" target="_blank" rel="noopener">
								platform.openai.com
							</a>
						</p>
					</div>

					<div className="ai-media-form-field">
						<label>{__('OpenAI Model', 'ai-media-seo')}</label>
						<select
							value={providers.openai?.model || 'gpt-4o'}
							onChange={(e) => updateProvider('openai', 'model', e.target.value)}
						>
							<option value="gpt-4o">GPT-4o (Recommended)</option>
							<option value="gpt-4o-mini">GPT-4o Mini (Cheaper)</option>
							<option value="gpt-4-turbo">GPT-4 Turbo</option>
						</select>
					</div>
				</div>

				{/* Quality Rules Section */}
				<div className="ai-media-settings-section">
					<h2>{__('Quality Rules', 'ai-media-seo')}</h2>

					<div className="ai-media-form-field">
						<label>{__('Auto-approve Threshold', 'ai-media-seo')}</label>
						<input
							type="number"
							min="0"
							max="1"
							step="0.05"
							value={settings.auto_approve_threshold || 0.85}
							onChange={(e) => updateSetting('auto_approve_threshold', parseFloat(e.target.value))}
						/>
						<p className="description">
							{__('Automatically approve metadata with score above this threshold (0-1).', 'ai-media-seo')}
						</p>
					</div>

					<div className="ai-media-form-field">
						<label>{__('ALT Text Max Length', 'ai-media-seo')}</label>
						<input
							type="number"
							min="50"
							max="200"
							value={settings.alt_max_length || 125}
							onChange={(e) => updateSetting('alt_max_length', parseInt(e.target.value))}
						/>
						<p className="description">
							{__('Maximum characters for ALT text (recommended: 125).', 'ai-media-seo')}
						</p>
					</div>
				</div>

				{/* Processing Section */}
				<div className="ai-media-settings-section">
					<h2>{__('Processing Settings', 'ai-media-seo')}</h2>

					<div className="ai-media-form-field">
						<label>{__('Batch Size', 'ai-media-seo')}</label>
						<input
							type="number"
							min="10"
							max="100"
							value={settings.batch_size || 50}
							onChange={(e) => updateSetting('batch_size', parseInt(e.target.value))}
						/>
						<p className="description">
							{__('Number of images to process in one batch.', 'ai-media-seo')}
						</p>
					</div>

					<div className="ai-media-form-field">
						<label>{__('Rate Limit (requests/minute)', 'ai-media-seo')}</label>
						<input
							type="number"
							min="10"
							max="500"
							value={settings.rate_limit_rpm || 120}
							onChange={(e) => updateSetting('rate_limit_rpm', parseInt(e.target.value))}
						/>
						<p className="description">
							{__('Maximum API requests per minute.', 'ai-media-seo')}
						</p>
					</div>
				</div>

				{/* Site Context Section */}
				<div className="ai-media-settings-section">
					<h2>{__('Site Context', 'ai-media-seo')}</h2>

					<div className="ai-media-form-field">
						<label>{__('Site Topic/Description', 'ai-media-seo')}</label>
						<textarea
							rows="3"
							value={settings.site_topic || ''}
							onChange={(e) => updateSetting('site_topic', e.target.value)}
							placeholder={__('e.g., Technology blog about web development and AI', 'ai-media-seo')}
						/>
						<p className="description">
							{__('Helps AI understand your site\'s context for better metadata generation.', 'ai-media-seo')}
						</p>
					</div>
				</div>

				{/* Save Button */}
				<div className="ai-media-settings-footer">
					<button
						type="submit"
						className="button button-primary button-large"
						disabled={saving}
					>
						{saving ? __('Saving...', 'ai-media-seo') : __('Save Settings', 'ai-media-seo')}
					</button>
				</div>
			</form>
		</div>
	);
};

// Initialize
const container = document.getElementById('ai-media-settings-root');
if (container) {
	const root = createRoot(container);
	root.render(<Settings />);
}
