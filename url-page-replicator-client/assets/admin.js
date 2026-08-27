jQuery(document).ready(function($) {
	// 1. URL Replicator Form Submit
	$('#upr-replicator-form').on('submit', function(e) {
		e.preventDefault();
		const $form = $(this);
		const $btn = $form.find('.upr-btn-submit');
		const $feedback = $('#upr-feedback-url');
		const $progressContainer = $('#upr-progress-container-url');
		const $progressBarFill = $('#upr-progress-bar-fill-url');
		const $progressPercent = $('#upr-progress-percent-url');
		const $progressStatus = $('#upr-progress-status-url');
		
		const targetUrl = $('#target_url').val().trim();
		if (!targetUrl) return;

		// UI Loading state
		$btn.prop('disabled', true).css('opacity', 0.6);
		$feedback.addClass('hidden').removeClass('success error').html('');
		$progressContainer.removeClass('hidden');
		$progressBarFill.css('width', '0%');
		$progressPercent.text('0%');
		$progressStatus.text('Contacting replication server...');

		// Animate progress bar smoothly
		let progress = 0;
		const progressInterval = setInterval(() => {
			if (progress < 40) {
				progress += 5;
			} else if (progress < 75) {
				progress += 2;
			} else if (progress < 95) {
				progress += 0.5;
			}
			$progressBarFill.css('width', progress + '%');
			$progressPercent.text(Math.round(progress) + '%');
			if (progress > 40 && progress < 75) {
				$progressStatus.text('Downloading page assets and stylesheets...');
			} else if (progress >= 75) {
				$progressStatus.text('Applying proxy sandboxing and script rewrites...');
			}
		}, 600);

		// AJAX Call
		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'upr_client_run_url_replication',
				nonce: upr_client_ajax.nonce,
				target_url: targetUrl
			},
			success: function(res) {
				clearInterval(progressInterval);
				if (res.success) {
					$progressBarFill.css('width', '100%');
					$progressPercent.text('100%');
					$progressStatus.text('Successfully compiled and saved!');
					
					setTimeout(() => {
						$progressContainer.addClass('hidden');
						$btn.prop('disabled', false).css('opacity', 1);
						$feedback.removeClass('hidden').addClass('success').html(
							`Replication success! <a href="${res.data.url}" target="_blank">View Replicated Page &rarr;</a>`
						);
						// Refresh local replicas list
						uprClientReloadReplicasTable();
					}, 1000);
				} else {
					clearInterval(progressInterval);
					$progressContainer.addClass('hidden');
					$btn.prop('disabled', false).css('opacity', 1);
					$feedback.removeClass('hidden').addClass('error').html(
						`<strong>Compilation Error:</strong> ${res.data.message}`
					);
				}
			},
			error: function(xhr, status, err) {
				clearInterval(progressInterval);
				$progressContainer.addClass('hidden');
				$btn.prop('disabled', false).css('opacity', 1);
				$feedback.removeClass('hidden').addClass('error').html(
					`<strong>API Request Failed:</strong> Server timeout or connection timed out.`
				);
			}
		});
	});

	// 2. Figma Compiler Form Submit
	$('#upr-figma-form').on('submit', function(e) {
		e.preventDefault();
		const $form = $(this);
		const $btn = $form.find('.upr-btn-submit');
		const $feedback = $('#upr-feedback-figma');
		const $progressContainer = $('#upr-progress-container-figma');
		const $progressBarFill = $('#upr-progress-bar-fill-figma');
		const $progressPercent = $('#upr-progress-percent-figma');
		const $progressStatus = $('#upr-progress-status-figma');

		const desktopUrl = $('#desktop_url').val().trim();
		const tabletUrl  = $('#tablet_url').val().trim();
		const mobileUrl  = $('#mobile_url').val().trim();
		if (!desktopUrl) return;

		// UI Loading state
		$btn.prop('disabled', true).css('opacity', 0.6);
		$feedback.addClass('hidden').removeClass('success error').html('');
		$progressContainer.removeClass('hidden');
		$progressBarFill.css('width', '0%');
		$progressPercent.text('0%');
		$progressStatus.text('Initializing Figma API nodes compiler...');

		// Animate figma progress bar smoothly
		let progress = 0;
		const progressInterval = setInterval(() => {
			if (progress < 30) {
				progress += 3;
			} else if (progress < 70) {
				progress += 1.5;
			} else if (progress < 95) {
				progress += 0.4;
			}
			$progressBarFill.css('width', progress + '%');
			$progressPercent.text(Math.round(progress) + '%');
			if (progress > 30 && progress < 70) {
				$progressStatus.text('Parsing Figma layout nodes & compiling CSS Flexbox structures...');
			} else if (progress >= 70) {
				$progressStatus.text('Fetching vector nodes from Figma and saving images...');
			}
		}, 800);

		// AJAX Call
		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'upr_client_run_figma_replication',
				nonce: upr_client_ajax.nonce,
				desktop_url: desktopUrl,
				tablet_url: tabletUrl,
				mobile_url: mobileUrl
			},
			success: function(res) {
				clearInterval(progressInterval);
				if (res.success) {
					$progressBarFill.css('width', '100%');
					$progressPercent.text('100%');
					$progressStatus.text('Successfully compiled and saved responsive mockup!');

					setTimeout(() => {
						$progressContainer.addClass('hidden');
						$btn.prop('disabled', false).css('opacity', 1);
						$feedback.removeClass('hidden').addClass('success').html(
							`Figma compilation success! <a href="${res.data.url}" target="_blank">View Page &rarr;</a>`
						);
						// Refresh local replicas list
						uprClientReloadReplicasTable();
					}, 1000);
				} else {
					clearInterval(progressInterval);
					$progressContainer.addClass('hidden');
					$btn.prop('disabled', false).css('opacity', 1);
					
					let errorHtml = `<strong>Figma Compilation Error:</strong> ${res.data.message}`;
					if (res.data.debug) {
						errorHtml += `<br/><br/><strong>Figma API Debug Data:</strong><pre style="background: #f1f5f9; padding: 10px; border-radius: 4px; overflow: auto; text-align: left; font-size: 11px; max-height: 250px; border: 1px solid #cbd5e1; color: #334155; font-family: monospace;">${JSON.stringify(res.data.debug, null, 2)}</pre>`;
					}
					
					$feedback.removeClass('hidden').addClass('error').html(errorHtml);
				}
			},
			error: function() {
				clearInterval(progressInterval);
				$progressContainer.addClass('hidden');
				$btn.prop('disabled', false).css('opacity', 1);
				$feedback.removeClass('hidden').addClass('error').html(
					`<strong>API Request Failed:</strong> Figma compiling request timed out.`
				);
			}
		});
	});

	// 3. Dynamic delete handler without page reload
	$(document).on('click', '.upr-client-delete-btn', function(e) {
		e.preventDefault();
		const $btn = $(this);
		const id = $btn.data('id');
		const $row = $btn.closest('tr');

		if (!confirm('Are you sure you want to delete this replicated page?')) {
			return;
		}

		$btn.prop('disabled', true).css('opacity', 0.5);

		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'upr_client_delete_page',
				nonce: upr_client_ajax.nonce,
				delete_id: id
			},
			success: function(res) {
				if (res.success) {
					$row.fadeOut(300, function() {
						$(this).remove();
						if ($('.upr-table tbody tr').length === 0) {
							$('.upr-table-wrapper').html(
								`<div class="upr-no-items" style="text-align: center; padding: 40px 20px;">` +
									`<span class="dashicons dashicons-info" style="font-size: 48px; width: 48px; height: 48px; color: #cbd5e1;"></span>` +
									`<p>No pages replicated yet.</p>` +
								`</div>`
							);
						}
					});
				} else {
					alert(res.data.message || 'Failed to delete replicated page.');
					$btn.prop('disabled', false).css('opacity', 1);
				}
			},
			error: function() {
				alert('Connection error. Failed to delete replicated page.');
				$btn.prop('disabled', false).css('opacity', 1);
			}
		});
	});

	// Helper function to reload/refresh the table listing dynamically
	function uprClientReloadReplicasTable() {
		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'GET',
			data: {
				action: 'upr_client_get_replicas_table_html',
				nonce: upr_client_ajax.nonce
			},
			success: function(html) {
				$('.upr-table-wrapper').html(html);
			}
		});
	}

	// Local Draft Persistence Functions (ONLY text metadata to avoid QuotaExceededError)
	function uprSaveSectionsDraft() {
		const draft = {
			title: $('#page_title').val(),
			python_url: $('#python_url').val(),
			sections: []
		};
		
		$('.upr-section-card').each(function() {
			const $card = $(this);
			const section = {
				description: $card.find('.sec-desc').val(),
				css: $card.find('.sec-css').val(),
				elements: []
			};
			
			$card.find('.upr-element-row').each(function() {
				const $el = $(this);
				section.elements.push({
					type: $el.find('.el-type').val(),
					content: $el.find('.el-content').val(),
					css: $el.find('.el-css').val()
				});
			});
			
			draft.sections.push(section);
		});
		
		localStorage.setItem('upr_sections_draft_text', JSON.stringify(draft));
	}

	function uprLoadSectionsDraft() {
		const draftStr = localStorage.getItem('upr_sections_draft_text');
		if (!draftStr) return;
		
		try {
			const draft = JSON.parse(draftStr);
			if (draft.title) $('#page_title').val(draft.title);
			if (draft.python_url) $('#python_url').val(draft.python_url);
			
			if (draft.sections && draft.sections.length > 0) {
				$('#upr-sections-list-container').html(''); // Clear defaults
				sectionCounter = 0; // Reset counter
				
				draft.sections.forEach(sec => {
					// Dynamically add a section card
					$('#upr-add-section-btn').trigger('click');
					const $newCard = $('.upr-section-card').last();
					
					$newCard.find('.sec-desc').val(sec.description);
					$newCard.find('.sec-css').val(sec.css);
					
					if (sec.elements && sec.elements.length > 0) {
						sec.elements.forEach(el => {
							// Dynamically add element row
							$newCard.find('.upr-add-element-btn').trigger('click');
							const $newEl = $newCard.find('.upr-element-row').last();
							$newEl.find('.el-type').val(el.type);
							$newEl.find('.el-content').val(el.content);
							$newEl.find('.el-css').val(el.css);
						});
					}
				});
			}
		} catch (e) {
			console.error('Failed to load sections draft:', e);
		}
	}

	// Server Draft Persistence (Database options, quota-free!)
	function uprSaveFullServerDraft() {
		const pageTitle = $('#page_title').val().trim();
		if (!pageTitle) {
			alert('Please enter a Target Page Title first to save your draft under that name.');
			return;
		}

		const draft = {
			title: pageTitle,
			python_url: $('#python_url').val(),
			main_image_b64: $('#main_image').data('draft-b64'),
			main_image_filename: $('#main_image').data('draft-filename'),
			main_pdf_b64: $('#main_pdf').data('draft-b64'),
			main_pdf_filename: $('#main_pdf').data('draft-filename'),
			sections: []
		};
		
		$('.upr-section-card').each(function() {
			const $card = $(this);
			const section = {
				description: $card.find('.sec-desc').val(),
				css: $card.find('.sec-css').val(),
				section_image_b64: $card.find('.sec-image').data('draft-b64'),
				section_image_filename: $card.find('.sec-image').data('draft-filename'),
				section_pdf_b64: $card.find('.sec-pdf').data('draft-b64'),
				section_pdf_filename: $card.find('.sec-pdf').data('draft-filename'),
				section_assets: $card.find('.sec-assets').data('draft-assets'),
				elements: []
			};
			
			$card.find('.upr-element-row').each(function() {
				const $el = $(this);
				section.elements.push({
					type: $el.find('.el-type').val(),
					content: $el.find('.el-content').val(),
					css: $el.find('.el-css').val()
				});
			});
			
			draft.sections.push(section);
		});

		$('#upr-draft-status').fadeIn(150).text('Uploading draft to server database...').css('color', '#4f46e5');

		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'upr_client_save_draft_option',
				nonce: upr_client_ajax.nonce,
				title: pageTitle,
				draft_data: JSON.stringify(draft)
			},
			success: function(res) {
				if (res.success) {
					$('#upr-draft-status').text('Draft saved successfully!').css('color', '#10b981').delay(2500).fadeOut(300);
					// Refresh drafts table
					$('#upr-drafts-archive-table-container').html(res.data.table_html);
				} else {
					$('#upr-draft-status').text('Database save failed.').css('color', '#dc3232').delay(2500).fadeOut(300);
				}
			},
			error: function() {
				$('#upr-draft-status').text('Connection failed saving draft.').css('color', '#dc3232').delay(2500).fadeOut(300);
			}
		});
	}

	function uprLoadFullServerDraft(draftTitle) {
		if (!draftTitle) {
			alert('Please select a draft from the Saved Drafts Archive table below.');
			return;
		}

		$('#upr-draft-status').fadeIn(150).text(`Loading draft "${draftTitle}"...`).css('color', '#4f46e5');

		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'upr_client_load_draft_option',
				nonce: upr_client_ajax.nonce,
				title: draftTitle
			},
			success: function(res) {
				if (res.success && res.data && res.data.draft_data) {
					try {
						const draft = JSON.parse(res.data.draft_data);
						
						if (draft.title) $('#page_title').val(draft.title);
						if (draft.python_url) $('#python_url').val(draft.python_url);
						
						if (draft.main_image_b64) {
							$('#main_image').data('draft-b64', draft.main_image_b64);
							$('#main_image').data('draft-filename', draft.main_image_filename);
							$('#main-image-preview').html(`<em>Loaded file: ${draft.main_image_filename}</em>`).show();
						} else {
							$('#main_image').data('draft-b64', null);
							$('#main-image-preview').hide();
						}

						if (draft.main_pdf_b64) {
							$('#main_pdf').data('draft-b64', draft.main_pdf_b64);
							$('#main_pdf').data('draft-filename', draft.main_pdf_filename);
							$('#main-pdf-preview').html(`<em>Loaded file: ${draft.main_pdf_filename}</em>`).show();
						} else {
							$('#main_pdf').data('draft-b64', null);
							$('#main-pdf-preview').hide();
						}
						
						if (draft.sections && draft.sections.length > 0) {
							$('#upr-sections-list-container').html(''); // Clear defaults
							sectionCounter = 0; // Reset counter
							
							draft.sections.forEach(sec => {
								// Dynamically add a section card
								$('#upr-add-section-btn').trigger('click');
								const $newCard = $('.upr-section-card').last();
								
								$newCard.find('.sec-desc').val(sec.description);
								$newCard.find('.sec-css').val(sec.css);
								
								// Restore files data
								if (sec.section_image_b64) {
									$newCard.find('.sec-image').data('draft-b64', sec.section_image_b64);
									$newCard.find('.sec-image').data('draft-filename', sec.section_image_filename);
									$newCard.find('.sec-image-preview').html(`<em>Loaded file: ${sec.section_image_filename}</em>`).show();
								} else {
									$newCard.find('.sec-image').data('draft-b64', null);
									$newCard.find('.sec-image-preview').hide();
								}

								if (sec.section_pdf_b64) {
									$newCard.find('.sec-pdf').data('draft-b64', sec.section_pdf_b64);
									$newCard.find('.sec-pdf').data('draft-filename', sec.section_pdf_filename);
									$newCard.find('.sec-pdf-preview').html(`<em>Loaded file: ${sec.section_pdf_filename}</em>`).show();
								} else {
									$newCard.find('.sec-pdf').data('draft-b64', null);
									$newCard.find('.sec-pdf-preview').hide();
								}
								
								if (sec.section_assets) {
									$newCard.find('.sec-assets').data('draft-assets', sec.section_assets);
									const numFiles = Object.keys(sec.section_assets).length;
									$newCard.find('.sec-assets-preview').html(`<em>Loaded ${numFiles} assets</em>`).show();
								} else {
									$newCard.find('.sec-assets').data('draft-assets', null);
									$newCard.find('.sec-assets-preview').hide();
								}
								
								if (sec.elements && sec.elements.length > 0) {
									sec.elements.forEach(el => {
										// Dynamically add element row
										$newCard.find('.upr-add-element-btn').trigger('click');
										const $newEl = $newCard.find('.upr-element-row').last();
										$newEl.find('.el-type').val(el.type);
										$newEl.find('.el-content').val(el.content);
										$newEl.find('.el-css').val(el.css);
									});
								}
							});
						}
						$('#upr-draft-status').text('Draft loaded successfully!').css('color', '#10b981').delay(2500).fadeOut(300);
					} catch(e) {
						$('#upr-draft-status').text('Error parsing server draft payload.').css('color', '#dc3232').delay(2500).fadeOut(300);
					}
				} else {
					$('#upr-draft-status').text('Failed to load draft from server.').css('color', '#dc3232').delay(2500).fadeOut(300);
				}
			},
			error: function() {
				$('#upr-draft-status').text('Server connection failed.').css('color', '#dc3232').delay(2500).fadeOut(300);
			}
		});
	}

	// Bind Table Load Click Trigger
	$(document).on('click', '.upr-client-load-draft-trigger', function(e) {
		e.preventDefault();
		const title = $(this).data('title');
		uprLoadFullServerDraft(title);
	});

	// Bind Table Delete Click Trigger
	$(document).on('click', '.upr-client-delete-draft-trigger', function(e) {
		e.preventDefault();
		const title = $(this).data('title');
		if (!confirm(`Are you sure you want to delete the saved draft "${title}"?`)) {
			return;
		}

		$('#upr-draft-status').fadeIn(150).text('Deleting draft...').css('color', '#4f46e5');

		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'upr_client_delete_draft_option',
				nonce: upr_client_ajax.nonce,
				title: title
			},
			success: function(res) {
				if (res.success) {
					$('#upr-draft-status').text('Draft deleted successfully.').css('color', '#10b981').delay(2500).fadeOut(300);
					$('#upr-drafts-archive-table-container').html(res.data.table_html);
				} else {
					$('#upr-draft-status').text('Deletion failed.').css('color', '#dc3232').delay(2500).fadeOut(300);
				}
			},
			error: function() {
				$('#upr-draft-status').text('Connection error during deletion.').css('color', '#dc3232').delay(2500).fadeOut(300);
			}
		});
	});

	// Listen for file selections and cache base64 data attributes immediately
	$(document).on('change', '#main_image', async function() {
		const file = this.files[0];
		if (file) {
			const b64 = await readFileAsData(file);
			$(this).data('draft-b64', b64);
			$(this).data('draft-filename', file.name);
			$('#main-image-preview').html(`<em>Loaded file: ${file.name}</em>`).show();
			uprSaveSectionsDraft();
		}
	});

	$(document).on('change', '#main_pdf', async function() {
		const file = this.files[0];
		if (file) {
			const b64 = await readFileAsData(file);
			$(this).data('draft-b64', b64);
			$(this).data('draft-filename', file.name);
			$('#main-pdf-preview').html(`<em>Loaded file: ${file.name}</em>`).show();
			uprSaveSectionsDraft();
		}
	});

	$(document).on('change', '.sec-image', async function() {
		const file = this.files[0];
		if (file) {
			const b64 = await readFileAsData(file);
			$(this).data('draft-b64', b64);
			$(this).data('draft-filename', file.name);
			$(this).siblings('.sec-image-preview').html(`<em>Loaded file: ${file.name}</em>`).show();
			uprSaveSectionsDraft();
		}
	});

	$(document).on('change', '.sec-pdf', async function() {
		const file = this.files[0];
		if (file) {
			const b64 = await readFileAsData(file);
			$(this).data('draft-b64', b64);
			$(this).data('draft-filename', file.name);
			$(this).siblings('.sec-pdf-preview').html(`<em>Loaded file: ${file.name}</em>`).show();
			uprSaveSectionsDraft();
		}
	});

	$(document).on('change', '.sec-assets', async function() {
		const files = this.files;
		const assets = {};
		for (let i = 0; i < files.length; i++) {
			const file = files[i];
			const isSvg = file.name.endsWith('.svg');
			assets[file.name] = await readFileAsData(file, isSvg);
		}
		$(this).data('draft-assets', assets);
		$(this).siblings('.sec-assets-preview').html(`<em>Loaded ${files.length} assets</em>`).show();
		uprSaveSectionsDraft();
	});

	// Trigger save on changes
	$(document).on('input change', '#upr-sections-compiler-form input, #upr-sections-compiler-form textarea, #upr-sections-compiler-form select', function() {
		uprSaveSectionsDraft();
	});

	// Trigger save on structural DOM changes
	$(document).on('click', '#upr-add-section-btn, .upr-remove-section-btn, .upr-add-element-btn, .upr-remove-element-btn', function() {
		setTimeout(uprSaveSectionsDraft, 100);
	});

	// Save draft button click handler
	$(document).on('click', '#upr-save-draft-btn', function(e) {
		e.preventDefault();
		uprSaveFullServerDraft();
	});

	// Load draft button click handler
	$(document).on('click', '#upr-load-draft-btn', function(e) {
		e.preventDefault();
		const title = $('#page_title').val().trim();
		uprLoadFullServerDraft(title);
	});

	// 4. Section Compiler Form & Dynamic Builder
	let sectionCounter = 0;

	// Load saved draft on ready
	setTimeout(uprLoadSectionsDraft, 150);

	// Add new Section Card
	$('#upr-add-section-btn').on('click', function() {
		sectionCounter++;
		const cardHtml = `
			<div class="upr-section-card" data-id="${sectionCounter}" style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 20px; position: relative; margin-top: 15px;">
				<button type="button" class="upr-remove-section-btn" style="position: absolute; top: 15px; right: 15px; background: none; border: none; color: #dc3232; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
					<span class="dashicons dashicons-dismiss" style="font-size: 16px; width: 16px; height: 16px;"></span> Delete Section
				</button>
				<h4 style="margin: 0 0 15px 0; font-size: 16px; color: #1e293b;">Section #${sectionCounter}</h4>
				
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
					<div>
						<label style="display: block; font-weight: 600; margin-bottom: 6px;">Section Description / Keywords</label>
						<textarea class="sec-desc" placeholder="e.g. Header bar containing navigation links, a call-to-action button, and brand logo" style="width: 100%; height: 80px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box;" required></textarea>
					</div>
					<div>
						<label style="display: block; font-weight: 600; margin-bottom: 6px;">Section Container CSS (from Figma)</label>
						<textarea class="sec-css" placeholder="e.g. display: flex; justify-content: space-between; padding: 20px 100px;" style="width: 100%; height: 80px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; font-family: monospace;" required></textarea>
					</div>
				</div>

				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
					<div>
						<label style="display: block; font-weight: 600; margin-bottom: 6px;">Section Screenshot Image (PNG/JPG)</label>
						<input type="file" class="sec-image" accept="image/*" style="margin-bottom: 10px;" />
						<div class="sec-image-preview" style="font-size: 11px; color: #4f46e5; margin-top: 4px; display: none;"></div>
						
						<label style="display: block; font-weight: 600; margin-bottom: 6px; margin-top: 10px;">Section Vector PDF</label>
						<input type="file" class="sec-pdf" accept="application/pdf" />
						<div class="sec-pdf-preview" style="font-size: 11px; color: #4f46e5; margin-top: 4px; display: none;"></div>
					</div>
					<div>
						<label style="display: block; font-weight: 600; margin-bottom: 6px;">Upload SVG Vectors / Section Assets</label>
						<input type="file" class="sec-assets" multiple accept="image/*,image/svg+xml" style="width: 100%;" />
						<div class="sec-assets-preview" style="font-size: 11px; color: #4f46e5; margin-top: 4px; display: none;"></div>
					</div>
				</div>

				<h5 style="margin: 20px 0 10px 0; font-size: 14px; color: #334155;">Child Elements / Blocks</h5>
				<div class="upr-elements-container" style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px;">
					<!-- Elements will be added here -->
				</div>

				<button type="button" class="upr-add-element-btn button button-secondary" style="height: 30px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
					<span class="dashicons dashicons-plus-alt2" style="font-size: 14px; width: 14px; height: 14px; margin-top: 1px;"></span> Add Element
				</button>
			</div>
		`;
		$('#upr-sections-list-container').append(cardHtml);
	});

	// Delete Section Card
	$(document).on('click', '.upr-remove-section-btn', function() {
		$(this).closest('.upr-section-card').remove();
	});

	// Add Child Element Row inside Section Card
	$(document).on('click', '.upr-add-element-btn', function() {
		const $container = $(this).siblings('.upr-elements-container');
		const elementHtml = `
			<div class="upr-element-row" style="display: flex; gap: 10px; align-items: flex-start; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; position: relative;">
				<div style="flex: 1;">
					<label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: #64748b;">Element Type</label>
					<select class="el-type" style="width: 100%; height: 32px; border: 1px solid #cbd5e1; border-radius: 4px;">
						<option value="div">Standard Block (Div)</option>
						<option value="button">Button</option>
						<option value="link">Link</option>
						<option value="input">Input Field</option>
						<option value="textarea">Text Area</option>
						<option value="image">Vector / Image SVG</option>
					</select>
				</div>
				<div style="flex: 2;">
					<label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: #64748b;">Content / Label / Filename</label>
					<input type="text" class="el-content" placeholder="e.g. Request a quote OR logo.svg" style="width: 100%; height: 32px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 0 8px; box-sizing: border-box;" required />
				</div>
				<div style="flex: 3;">
					<label style="display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: #64748b;">Element CSS (from Figma)</label>
					<input type="text" class="el-css" placeholder="e.g. padding: 20px 35px; background: #191A23; border-radius: 14px;" style="width: 100%; height: 32px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 0 8px; box-sizing: border-box; font-family: monospace;" required />
				</div>
				<button type="button" class="upr-remove-element-btn" style="background: none; border: none; color: #dc3232; cursor: pointer; margin-top: 24px;" title="Remove Element">
					<span class="dashicons dashicons-trash" style="font-size: 18px; width: 18px; height: 18px;"></span>
				</button>
			</div>
		`;
		$container.append(elementHtml);
	});

	// Remove Child Element Row
	$(document).on('click', '.upr-remove-element-btn', function() {
		$(this).closest('.upr-element-row').remove();
	});

	// File to Base64/Text Helper (Async)
	function readFileAsData(file, readAsText = false) {
		return new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onload = () => resolve(reader.result);
			reader.onerror = (e) => reject(e);
			if (readAsText) {
				reader.readAsText(file);
			} else {
				reader.readAsDataURL(file);
			}
		});
	}

	// Form Submission & API call compilation
	$('#upr-sections-compiler-form').on('submit', async function(e) {
		e.preventDefault();
		const $form = $(this);
		const $btn = $form.find('button[type="submit"]');
		const $feedback = $('#upr-feedback-sections');
		const $progressContainer = $('#upr-progress-container-sections');
		const $progressBarFill = $('#upr-progress-bar-fill-sections');
		const $progressPercent = $('#upr-progress-percent-sections');
		const $progressStatus = $('#upr-progress-status-sections');

		const pageTitle = $('#page_title').val().trim();
		const pythonUrl = $('#python_url').val().trim();
		if (!pageTitle || !pythonUrl) return;

		// UI Loading states
		$btn.prop('disabled', true).css('opacity', 0.6);
		$feedback.addClass('hidden').removeClass('success error').html('');
		$progressContainer.removeClass('hidden');
		$progressBarFill.css('width', '10%');
		$progressPercent.text('10%');
		$progressStatus.text('Reading local files and assets...');

		try {
			const payload = {
				title: pageTitle,
				gemini_api_key: upr_client_ajax.gemini_api_key,
				assets: {},
				sections: []
			};

			// 1. Process Main Page Image & PDF Files
			const mainImageFile = $('#main_image')[0].files[0];
			if (mainImageFile) {
				payload.main_design_b64 = await readFileAsData(mainImageFile);
			} else if ($('#main_image').data('draft-b64')) {
				payload.main_design_b64 = $('#main_image').data('draft-b64');
			}

			const mainPdfFile = $('#main_pdf')[0].files[0];
			if (mainPdfFile) {
				payload.main_pdf_b64 = await readFileAsData(mainPdfFile);
			} else if ($('#main_pdf').data('draft-b64')) {
				payload.main_pdf_b64 = $('#main_pdf').data('draft-b64');
			}

			// 2. Gather Assets from Section Cards
			const $cards = $('.upr-section-card');
			if ($cards.length === 0) {
				throw new Error('Please add at least one section card before compiling.');
			}

			// Process each card
			for (let i = 0; i < $cards.length; i++) {
				const $card = $($cards[i]);
				const secDesc = $card.find('.sec-desc').val().trim();
				const secCss = $card.find('.sec-css').val().trim();
				
				const sectionData = {
					description: secDesc,
					css: secCss,
					elements: []
				};

				// Section Image base64
				const secImageFile = $card.find('.sec-image')[0].files[0];
				if (secImageFile) {
					sectionData.section_image_b64 = await readFileAsData(secImageFile);
				} else if ($card.find('.sec-image').data('draft-b64')) {
					sectionData.section_image_b64 = $card.find('.sec-image').data('draft-b64');
				}

				// Section PDF base64
				const secPdfFile = $card.find('.sec-pdf')[0].files[0];
				if (secPdfFile) {
					sectionData.section_pdf_b64 = await readFileAsData(secPdfFile);
				} else if ($card.find('.sec-pdf').data('draft-b64')) {
					sectionData.section_pdf_b64 = $card.find('.sec-pdf').data('draft-b64');
				}

				// Section Multiple Assets
				const secAssetFiles = $card.find('.sec-assets')[0].files;
				if (secAssetFiles && secAssetFiles.length > 0) {
					for (let a = 0; a < secAssetFiles.length; a++) {
						const file = secAssetFiles[a];
						const isSvg = file.name.endsWith('.svg');
						// Read SVGs as plaintext XML, others as base64 URLs
						payload.assets[file.name] = await readFileAsData(file, isSvg);
					}
				} else if ($card.find('.sec-assets').data('draft-assets')) {
					const cachedAssets = $card.find('.sec-assets').data('draft-assets');
					Object.assign(payload.assets, cachedAssets);
				}

				// Gather Child Elements
				const $elements = $card.find('.upr-element-row');
				$elements.each(function() {
					const $el = $(this);
					sectionData.elements.push({
						type: $el.find('.el-type').val(),
						content: $el.find('.el-content').val().trim(),
						css: $el.find('.el-css').val().trim()
					});
				});

				payload.sections.push(sectionData);
			}

			// 3. Send compiled JSON payload to Python API Web Service
			$progressBarFill.css('width', '35%');
			$progressPercent.text('35%');
			$progressStatus.text('Transmitting dataset to Python smart compiler...');

			const pythonResponse = await fetch(pythonUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify(payload)
			});

			if (!pythonResponse.ok) {
				const errText = await pythonResponse.text();
				throw new Error(`Python server error: ${pythonResponse.status} - ${errText}`);
			}

			const pythonResult = await pythonResponse.json();
			if (pythonResult.status !== 'success' || !pythonResult.data) {
				throw new Error(pythonResult.message || 'Python compilation failed.');
			}

			// 4. Save compiled HTML, CSS, and JS to WordPress local files
			$progressBarFill.css('width', '70%');
			$progressPercent.text('70%');
			$progressStatus.text('Saving layout codes and assets in WordPress...');

			// Helper for UTF-8 safe base64 encoding in JS to bypass WAF blocks
			function safeBtoa(str) {
				return btoa(encodeURIComponent(str).replace(/%([0-9A-F]{2})/g, function(match, p1) {
					return String.fromCharCode(parseInt(p1, 16));
				}));
			}

			$.ajax({
				url: upr_client_ajax.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'upr_client_save_compiled_page',
					nonce: upr_client_ajax.nonce,
					title: pageTitle,
					html: safeBtoa(pythonResult.data.html),
					css: safeBtoa(pythonResult.data.css),
					js: safeBtoa(pythonResult.data.js)
				},
				success: function(wpRes) {
					if (wpRes.success) {
						$progressBarFill.css('width', '100%');
						$progressPercent.text('100%');
						$progressStatus.text('Stitched successfully! Page is live!');

						setTimeout(() => {
							$progressContainer.addClass('hidden');
							$btn.prop('disabled', false).css('opacity', 1);
							$feedback.removeClass('hidden').addClass('success').html(
								`Smart layout compilation success! <a href="${wpRes.data.url}" target="_blank">View Live Page &rarr;</a>`
							);
							// Refresh local replicas list
							uprClientReloadReplicasTable();
						}, 1000);
					} else {
						$progressContainer.addClass('hidden');
						$btn.prop('disabled', false).css('opacity', 1);
						$feedback.removeClass('hidden').addClass('error').html(
							`<strong>WordPress Save Error:</strong> ${wpRes.data.message}`
						);
					}
				},
				error: function() {
					$progressContainer.addClass('hidden');
					$btn.prop('disabled', false).css('opacity', 1);
					$feedback.removeClass('hidden').addClass('error').html(
						`<strong>WordPress AJAX failed:</strong> Connection timeout during saving.`
					);
				}
			});

		} catch (error) {
			$progressContainer.addClass('hidden');
			$btn.prop('disabled', false).css('opacity', 1);
			$feedback.removeClass('hidden').addClass('error').html(
				`<strong>Compilation failed:</strong> ${error.message}`
			);
	});

	// 5. Import Page Form Submit
	$('#upr-import-form').on('submit', function(e) {
		e.preventDefault();
		const $form = $(this);
		const $btn = $form.find('.upr-btn-submit');
		const $feedback = $('#upr-feedback-import');
		const fileInput = $('#import_file')[0];

		if (fileInput.files.length === 0) return;

		$btn.prop('disabled', true).css('opacity', 0.6);
		$feedback.removeClass('hidden').css('color', '#4f46e5').html('Uploading and extracting ZIP package...');

		const formData = new FormData();
		formData.append('action', 'upr_import_page');
		formData.append('nonce', upr_client_ajax.nonce);
		formData.append('import_file', fileInput.files[0]);

		$.ajax({
			url: upr_client_ajax.ajax_url,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			dataType: 'json',
			success: function(res) {
				$btn.prop('disabled', false).css('opacity', 1);
				if (res.success) {
					$feedback.css('color', '#10b981').html(
						`Import success! <a href="${res.data.url}" target="_blank">View Imported Page &rarr;</a>`
					);
					// Refresh local replicas list
					uprClientReloadReplicasTable();
					$form.trigger('reset');
				} else {
					$feedback.css('color', '#dc3232').html(
						`<strong>Import Error:</strong> ${res.data.message}`
					);
				}
			},
			error: function() {
				$btn.prop('disabled', false).css('opacity', 1);
				$feedback.css('color', '#dc3232').html(
					`<strong>Request Failed:</strong> Connection error or file size exceeds maximum upload limit.`
				);
			}
		});
	});
});
