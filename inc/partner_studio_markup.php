<?php
// Markup of the Design Studio (from the website's page-design.php: stage, toolbar, add design / text, adjust).
// Included by partner/designs.php inside the product form; every control is type=button / unnamed, so nothing
// of it is posted — only the finished mock-ups (made[]).
?>
<section class="ds-studio" data-studio data-cfg="<?= h(json_encode(studio_config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
<div class="ds-stage-col">
				<div class="ds-views" role="tablist" aria-label="Print position">
					<button type="button" role="tab" class="ds-view" data-view="front">Front<b data-count="front"></b></button>
					<button type="button" role="tab" class="ds-view" data-view="back">Back<b data-count="back"></b></button>
				</div>

				<div class="ds-stage" data-stage tabindex="0" aria-label="Mockup preview. Drag to move the selected design; arrow keys nudge it.">
					<canvas data-canvas></canvas>
					<div class="ds-sel" data-sel hidden>
						<span class="h nw" data-h="scale"></span><span class="h ne" data-h="scale"></span>
						<span class="h sw" data-h="scale"></span><span class="h se" data-h="scale"></span>
						<span class="h rot" data-h="rotate" title="Rotate"></span>
						<div class="ds-sel-bar">
							<button type="button" data-sel-act="replace" data-sel-img>↻ Replace</button>
							<button type="button" data-sel-act="delete" class="danger">✕ Delete</button>
						</div>
					</div>
					<div class="ds-empty" data-empty>
						<button type="button" class="ds-empty-btn" data-upload-btn>
							<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
							<strong>Upload your design</strong>
							<small>PNG, JPG, WEBP, SVG or PDF · or drag & drop</small>
						</button>
					</div>
					<div class="ds-drop" data-drop hidden>Drop your image to add it</div>
					<span class="ds-measure" data-measure hidden></span>
				</div>

				<div class="ds-toolbar">
					<button type="button" class="ds-tool" data-act="undo" title="Undo (Ctrl+Z)" aria-label="Undo"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg></button>
					<button type="button" class="ds-tool" data-act="redo" title="Redo (Ctrl+Y)" aria-label="Redo"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/></svg></button>
					<label class="ds-switch"><input type="checkbox" data-guides checked> <span>Print area</span></label>
					<span class="ds-toolbar-sp"></span>
					<div class="ds-dl">
						<button type="button" class="ds-tool wide" data-act="download" aria-haspopup="true" aria-expanded="false" aria-controls="ds-dl-pop"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M5 20h14"/></svg> Download mockup</button>
						<div class="ds-dl-pop" id="ds-dl-pop" data-dl-pop role="dialog" aria-label="Download mockup" hidden>
							<p class="ds-dl-h">Which sides?</p>
							<label class="ds-check"><input type="checkbox" data-dl-view="front"> <span>Front <small data-dl-note="front"></small></span></label>
							<label class="ds-check"><input type="checkbox" data-dl-view="back"> <span>Back <small data-dl-note="back"></small></span></label>
							<button type="button" class="ds-btn block" data-dl-go>Download</button>
							<p class="ds-dl-foot">Each side saves as its own picture.</p>
						</div>
					</div>
				</div>

				<div class="ds-thumbs" data-thumbs></div>
			</div>

			
			<div class="ds-panel">

				

				<section class="ds-card">
					<h2 class="ds-h"><span>1</span> Add your design <em data-pos-label></em></h2>
					<div class="ds-add">
						<button type="button" class="ds-add-btn" data-upload-btn><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg> <span><strong>Upload image</strong><small>PNG, JPG, WEBP, SVG, PDF</small></span></button>
						<button type="button" class="ds-add-btn" data-add-text><span class="ds-t">T</span> <span><strong>Add text</strong><small>Name, slogan, number</small></span></button>
						<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,application/pdf,.pdf" multiple hidden data-file>
					</div>
					<ul class="ds-layers" data-layers></ul>
					<p class="ds-tip" data-tip>Tip: PNG with a transparent background gives the cleanest print. Use at least 2000 px wide for big prints.</p>
				</section>

				<section class="ds-card" data-editor hidden>
					<h2 class="ds-h"><span>2</span> Adjust <em data-edit-name></em></h2>

					<div class="ds-text-edit" data-text-edit hidden>
						<label class="field"><span>Text</span><textarea rows="2" data-tx="text"></textarea></label>

						<div class="ds-tx-bar">
							<button type="button" class="ds-font-btn" data-font-open aria-expanded="false"><small>Font</small><b data-font-name>Archivo</b><span aria-hidden="true">▾</span></button>
							<div class="ds-tx-tools" role="group" aria-label="Text style">
								<button type="button" data-txt="bold" aria-pressed="false" title="Bold"><b>B</b></button>
								<button type="button" data-txt="italic" aria-pressed="false" title="Italic"><i>I</i></button>
								<button type="button" data-txa="left" title="Align left"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></svg></button>
								<button type="button" data-txa="center" title="Centre"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 10h10M4 14h16M7 18h10"/></svg></button>
								<button type="button" data-txa="right" title="Align right"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M10 10h10M4 14h16M10 18h10"/></svg></button>
							</div>
							<select data-tx="case" aria-label="Letter case">
								<option value="none">As typed</option>
								<option value="upper">UPPERCASE</option>
								<option value="lower">lowercase</option>
								<option value="title">Title Case</option>
							</select>
						</div>
						<div class="ds-font-panel" data-font-panel hidden>
							<input type="search" placeholder="Search fonts…" data-font-search aria-label="Search fonts">
							<div class="ds-font-list" data-font-list></div>
						</div>

						<p class="ds-label">Quick styles</p>
						<div class="ds-tx-styles" data-tx-styles></div>

						<p class="ds-label">Text colour</p>
						<div class="ds-swatches" data-tx-colours></div>
						<label class="ds-check"><input type="checkbox" data-tx-gradon> <span>Gradient <small>(top colour → bottom colour)</small></span></label>
						<div class="ds-swatches" data-tx-grad hidden></div>

						<p class="ds-label">Outline</p>
						<div class="ds-swatches" data-tx-outline></div>
						<div class="ds-slider" data-ow-wrap>
							<label for="ds-tx-ow">Outline thickness <b data-tx-out="ow"></b></label>
							<input type="range" id="ds-tx-ow" min="1" max="20" step="1" data-tx-range="ow">
						</div>
						<label class="ds-check"><input type="checkbox" data-tx-hollow> <span>Outline only <small>(hollow letters)</small></span></label>


						<div class="ds-slider">
							<label for="ds-tx-curve">Curve <b data-tx-out="curve"></b></label>
							<input type="range" id="ds-tx-curve" min="-330" max="330" step="5" data-tx-range="curve">
						</div>
						<div class="ds-slider">
							<label for="ds-tx-ls">Letter spacing <b data-tx-out="ls"></b></label>
							<input type="range" id="ds-tx-ls" min="-10" max="60" step="1" data-tx-range="ls">
						</div>
						<div class="ds-slider" data-lh-wrap>
							<label for="ds-tx-lh">Line spacing <b data-tx-out="lh"></b></label>
							<input type="range" id="ds-tx-lh" min="70" max="200" step="5" data-tx-range="lh">
						</div>
						<button type="button" class="ds-btn ds-tx-dl" data-tx-dl>↓ Download text as PNG · high resolution</button>
					</div>

					<div class="ds-presets" data-presets></div>

					<div class="ds-slider">
						<label for="ds-size">Size <b data-size-out></b></label>
						<input type="range" id="ds-size" min="1" max="16" step="0.1" data-size>
					</div>
					<div class="ds-slider">
						<label for="ds-rot">Rotate <b data-rot-out></b></label>
						<input type="range" id="ds-rot" min="-180" max="180" step="1" data-rot>
					</div>

					<div class="ds-actions">
						<button type="button" data-do="centre">Centre</button>
						<button type="button" data-do="flip">Flip</button>
						<button type="button" data-do="fit">Fit area</button>
						<button type="button" data-do="dup">Duplicate</button>
						<button type="button" data-do="copyback" data-copy-label>Copy to back</button>
						<button type="button" data-do="replace" data-replace-btn>↻ Replace image</button>
						<button type="button" data-do="delete" class="danger">✕ Delete</button>
					</div>
					<div class="ds-bg" data-bg-wrap>
						<div class="ds-bg-head">
							<strong>Remove background</strong>
							<small>Tap a colour in the picture to remove it. Tap more colours to remove those too.</small>
						</div>
						<div class="ds-bg-body">
							<div class="ds-bg-preview"><canvas data-bg-canvas aria-label="Tap a colour to remove it"></canvas></div>
							<div class="ds-bg-tools">
								<div class="ds-bg-keys" data-bg-keys></div>
								<div class="ds-bg-quick">
									<button type="button" data-bg="auto">Auto</button>
									<button type="button" data-bg="white">+ White</button>
									<button type="button" data-bg="black">+ Black</button>
									<button type="button" data-bg="clear">Undo all</button>
								</div>
								<div class="ds-slider">
									<label for="ds-bg-tol">Strength <b data-bg-tol-out></b></label>
									<input type="range" id="ds-bg-tol" min="1" max="60" step="1" data-bg-tol>
								</div>
								<label class="ds-check"><input type="checkbox" data-bg-edge> <span>Only the background <small>(keeps the same colour inside your design)</small></span></label>
								<button type="button" class="ds-btn ds-bg-dl" data-bg="download">↓ Download PNG · full size</button>
							</div>
						</div>
					</div>
					<p class="ds-quality" data-quality></p>
				</section>
</div>
<div class="toast" role="status" aria-live="polite" data-toast hidden></div>
</section>
