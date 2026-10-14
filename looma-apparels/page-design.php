<?php
/**
 * Design your Product — live mockup studio (logic in assets/js/designer.js).
 *
 * @package Looma_Apparels
 */

get_header();

$looma_pre = isset( $_GET['product'] ) ? sanitize_title( wp_unslash( $_GET['product'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
?>
<main id="main" class="ds-page">
	<header class="ds-head">
		<div class="container">
			<nav class="crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'looma' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'looma' ); ?></a>
				<span aria-hidden="true">/</span> <span aria-current="page"><?php esc_html_e( 'Design your Product', 'looma' ); ?></span>
			</nav>
			<div class="ds-head-row">
				<div>
					<p class="eyebrow"><?php esc_html_e( 'Design Studio', 'looma' ); ?></p>
					<h1 class="ds-title"><?php esc_html_e( 'Design your Product', 'looma' ); ?></h1>
				</div>
				<p class="ds-lead"><?php esc_html_e( 'Upload your artwork, place it on the front or back, and see a live mockup with an instant price. Send it to us on WhatsApp in one tap.', 'looma' ); ?></p>
			</div>
		</div>
	</header>

	<section class="ds" data-studio data-preselect="<?php echo esc_attr( $looma_pre ); ?>">
		<div class="container ds-grid">

			<!-- ============ STAGE ============ -->
			<div class="ds-stage-col">
				<div class="ds-views" role="tablist" aria-label="<?php esc_attr_e( 'Print position', 'looma' ); ?>">
					<button type="button" role="tab" class="ds-view" data-view="front"><?php esc_html_e( 'Front', 'looma' ); ?><b data-count="front"></b></button>
					<button type="button" role="tab" class="ds-view" data-view="back"><?php esc_html_e( 'Back', 'looma' ); ?><b data-count="back"></b></button>
				</div>

				<div class="ds-stage" data-stage tabindex="0" aria-label="<?php esc_attr_e( 'Mockup preview. Drag to move the selected design; arrow keys nudge it.', 'looma' ); ?>">
					<canvas data-canvas></canvas>
					<div class="ds-sel" data-sel hidden>
						<span class="h nw" data-h="scale"></span><span class="h ne" data-h="scale"></span>
						<span class="h sw" data-h="scale"></span><span class="h se" data-h="scale"></span>
						<span class="h rot" data-h="rotate" title="<?php esc_attr_e( 'Rotate', 'looma' ); ?>"></span>
						<div class="ds-sel-bar">
							<button type="button" data-sel-act="replace" data-sel-img><?php esc_html_e( '↻ Replace', 'looma' ); ?></button>
							<button type="button" data-sel-act="delete" class="danger"><?php esc_html_e( '✕ Delete', 'looma' ); ?></button>
						</div>
					</div>
					<div class="ds-empty" data-empty>
						<button type="button" class="ds-empty-btn" data-upload-btn>
							<?php looma_the_icon( 'plus' ); ?>
							<strong><?php esc_html_e( 'Upload your design', 'looma' ); ?></strong>
							<small><?php esc_html_e( 'PNG, JPG, WEBP, SVG or PDF · or drag & drop', 'looma' ); ?></small>
						</button>
					</div>
					<div class="ds-drop" data-drop hidden><?php esc_html_e( 'Drop your image to add it', 'looma' ); ?></div>
					<span class="ds-measure" data-measure hidden></span>
				</div>

				<div class="ds-toolbar">
					<button type="button" class="ds-tool" data-act="undo" title="<?php esc_attr_e( 'Undo (Ctrl+Z)', 'looma' ); ?>" aria-label="<?php esc_attr_e( 'Undo', 'looma' ); ?>"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg></button>
					<button type="button" class="ds-tool" data-act="redo" title="<?php esc_attr_e( 'Redo (Ctrl+Y)', 'looma' ); ?>" aria-label="<?php esc_attr_e( 'Redo', 'looma' ); ?>"><svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/></svg></button>
					<label class="ds-switch"><input type="checkbox" data-guides checked> <span><?php esc_html_e( 'Print area', 'looma' ); ?></span></label>
					<span class="ds-toolbar-sp"></span>
					<button type="button" class="ds-tool wide" data-act="download"><?php looma_the_icon( 'arrow' ); ?> <?php esc_html_e( 'Download mockup', 'looma' ); ?></button>
				</div>

				<div class="ds-thumbs" data-thumbs></div>
			</div>

			<!-- ============ PANEL ============ -->
			<div class="ds-panel">

				<section class="ds-card">
					<h2 class="ds-h"><span>1</span> <?php esc_html_e( 'Choose your t-shirt', 'looma' ); ?></h2>
					<div class="ds-products" data-products role="radiogroup" aria-label="<?php esc_attr_e( 'T-shirt', 'looma' ); ?>"></div>
					<p class="ds-label"><?php esc_html_e( 'Colour', 'looma' ); ?>: <b data-colour-name></b></p>
					<div class="ds-colours" data-colours role="radiogroup" aria-label="<?php esc_attr_e( 'Colour', 'looma' ); ?>"></div>
				</section>

				<section class="ds-card">
					<h2 class="ds-h"><span>2</span> <?php esc_html_e( 'Add your design', 'looma' ); ?> <em data-pos-label></em></h2>
					<div class="ds-add">
						<button type="button" class="ds-add-btn" data-upload-btn><?php looma_the_icon( 'plus' ); ?> <span><strong><?php esc_html_e( 'Upload image', 'looma' ); ?></strong><small><?php esc_html_e( 'PNG, JPG, WEBP, SVG, PDF', 'looma' ); ?></small></span></button>
						<button type="button" class="ds-add-btn" data-add-text><span class="ds-t">T</span> <span><strong><?php esc_html_e( 'Add text', 'looma' ); ?></strong><small><?php esc_html_e( 'Name, slogan, number', 'looma' ); ?></small></span></button>
						<input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml,application/pdf,.pdf" multiple hidden data-file>
					</div>
					<ul class="ds-layers" data-layers></ul>
					<p class="ds-tip" data-tip><?php esc_html_e( 'Tip: PNG with a transparent background gives the cleanest print. Use at least 2000 px wide for big prints.', 'looma' ); ?></p>
				</section>

				<section class="ds-card" data-editor hidden>
					<h2 class="ds-h"><span>3</span> <?php esc_html_e( 'Adjust', 'looma' ); ?> <em data-edit-name></em></h2>

					<div class="ds-text-edit" data-text-edit hidden>
						<label class="field"><span><?php esc_html_e( 'Text', 'looma' ); ?></span><textarea rows="2" data-tx="text"></textarea></label>

						<div class="ds-tx-bar">
							<button type="button" class="ds-font-btn" data-font-open aria-expanded="false"><small><?php esc_html_e( 'Font', 'looma' ); ?></small><b data-font-name>Archivo</b><span aria-hidden="true">▾</span></button>
							<div class="ds-tx-tools" role="group" aria-label="<?php esc_attr_e( 'Text style', 'looma' ); ?>">
								<button type="button" data-txt="bold" aria-pressed="false" title="<?php esc_attr_e( 'Bold', 'looma' ); ?>"><b>B</b></button>
								<button type="button" data-txt="italic" aria-pressed="false" title="<?php esc_attr_e( 'Italic', 'looma' ); ?>"><i>I</i></button>
								<button type="button" data-txa="left" title="<?php esc_attr_e( 'Align left', 'looma' ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 10h10M4 14h16M4 18h10"/></svg></button>
								<button type="button" data-txa="center" title="<?php esc_attr_e( 'Centre', 'looma' ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 10h10M4 14h16M7 18h10"/></svg></button>
								<button type="button" data-txa="right" title="<?php esc_attr_e( 'Align right', 'looma' ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M10 10h10M4 14h16M10 18h10"/></svg></button>
							</div>
							<select data-tx="case" aria-label="<?php esc_attr_e( 'Letter case', 'looma' ); ?>">
								<option value="none"><?php esc_html_e( 'As typed', 'looma' ); ?></option>
								<option value="upper"><?php esc_html_e( 'UPPERCASE', 'looma' ); ?></option>
								<option value="lower"><?php esc_html_e( 'lowercase', 'looma' ); ?></option>
								<option value="title"><?php esc_html_e( 'Title Case', 'looma' ); ?></option>
							</select>
						</div>
						<div class="ds-font-panel" data-font-panel hidden>
							<input type="search" placeholder="<?php esc_attr_e( 'Search fonts…', 'looma' ); ?>" data-font-search aria-label="<?php esc_attr_e( 'Search fonts', 'looma' ); ?>">
							<div class="ds-font-list" data-font-list></div>
						</div>

						<p class="ds-label"><?php esc_html_e( 'Quick styles', 'looma' ); ?></p>
						<div class="ds-tx-styles" data-tx-styles></div>

						<p class="ds-label"><?php esc_html_e( 'Text colour', 'looma' ); ?></p>
						<div class="ds-swatches" data-tx-colours></div>
						<label class="ds-check"><input type="checkbox" data-tx-gradon> <span><?php esc_html_e( 'Gradient', 'looma' ); ?> <small><?php esc_html_e( '(top colour → bottom colour)', 'looma' ); ?></small></span></label>
						<div class="ds-swatches" data-tx-grad hidden></div>

						<p class="ds-label"><?php esc_html_e( 'Outline', 'looma' ); ?></p>
						<div class="ds-swatches" data-tx-outline></div>
						<div class="ds-slider" data-ow-wrap>
							<label for="ds-tx-ow"><?php esc_html_e( 'Outline thickness', 'looma' ); ?> <b data-tx-out="ow"></b></label>
							<input type="range" id="ds-tx-ow" min="1" max="20" step="1" data-tx-range="ow">
						</div>
						<label class="ds-check"><input type="checkbox" data-tx-hollow> <span><?php esc_html_e( 'Outline only', 'looma' ); ?> <small><?php esc_html_e( '(hollow letters)', 'looma' ); ?></small></span></label>


						<div class="ds-slider">
							<label for="ds-tx-curve"><?php esc_html_e( 'Curve', 'looma' ); ?> <b data-tx-out="curve"></b></label>
							<input type="range" id="ds-tx-curve" min="-330" max="330" step="5" data-tx-range="curve">
						</div>
						<div class="ds-slider">
							<label for="ds-tx-ls"><?php esc_html_e( 'Letter spacing', 'looma' ); ?> <b data-tx-out="ls"></b></label>
							<input type="range" id="ds-tx-ls" min="-10" max="60" step="1" data-tx-range="ls">
						</div>
						<div class="ds-slider" data-lh-wrap>
							<label for="ds-tx-lh"><?php esc_html_e( 'Line spacing', 'looma' ); ?> <b data-tx-out="lh"></b></label>
							<input type="range" id="ds-tx-lh" min="70" max="200" step="5" data-tx-range="lh">
						</div>
						<button type="button" class="btn btn-dark btn-sm ds-tx-dl" data-tx-dl>↓ <?php esc_html_e( 'Download text as PNG · high resolution', 'looma' ); ?></button>
					</div>

					<div class="ds-presets" data-presets></div>

					<div class="ds-slider">
						<label for="ds-size"><?php esc_html_e( 'Size', 'looma' ); ?> <b data-size-out></b></label>
						<input type="range" id="ds-size" min="1" max="16" step="0.1" data-size>
					</div>
					<div class="ds-slider">
						<label for="ds-rot"><?php esc_html_e( 'Rotate', 'looma' ); ?> <b data-rot-out></b></label>
						<input type="range" id="ds-rot" min="-180" max="180" step="1" data-rot>
					</div>

					<div class="ds-actions">
						<button type="button" data-do="centre"><?php esc_html_e( 'Centre', 'looma' ); ?></button>
						<button type="button" data-do="flip"><?php esc_html_e( 'Flip', 'looma' ); ?></button>
						<button type="button" data-do="fit"><?php esc_html_e( 'Fit area', 'looma' ); ?></button>
						<button type="button" data-do="dup"><?php esc_html_e( 'Duplicate', 'looma' ); ?></button>
						<button type="button" data-do="copyback" data-copy-label><?php esc_html_e( 'Copy to back', 'looma' ); ?></button>
						<button type="button" data-do="replace" data-replace-btn><?php esc_html_e( '↻ Replace image', 'looma' ); ?></button>
						<button type="button" data-do="delete" class="danger"><?php esc_html_e( '✕ Delete', 'looma' ); ?></button>
					</div>
					<div class="ds-bg" data-bg-wrap>
						<div class="ds-bg-head">
							<strong><?php esc_html_e( 'Remove background', 'looma' ); ?></strong>
							<small><?php esc_html_e( 'Tap a colour in the picture to remove it. Tap more colours to remove those too.', 'looma' ); ?></small>
						</div>
						<div class="ds-bg-body">
							<div class="ds-bg-preview"><canvas data-bg-canvas aria-label="<?php esc_attr_e( 'Tap a colour to remove it', 'looma' ); ?>"></canvas></div>
							<div class="ds-bg-tools">
								<div class="ds-bg-keys" data-bg-keys></div>
								<div class="ds-bg-quick">
									<button type="button" data-bg="auto"><?php esc_html_e( 'Auto', 'looma' ); ?></button>
									<button type="button" data-bg="white"><?php esc_html_e( '+ White', 'looma' ); ?></button>
									<button type="button" data-bg="black"><?php esc_html_e( '+ Black', 'looma' ); ?></button>
									<button type="button" data-bg="clear"><?php esc_html_e( 'Undo all', 'looma' ); ?></button>
								</div>
								<div class="ds-slider">
									<label for="ds-bg-tol"><?php esc_html_e( 'Strength', 'looma' ); ?> <b data-bg-tol-out></b></label>
									<input type="range" id="ds-bg-tol" min="1" max="60" step="1" data-bg-tol>
								</div>
								<label class="ds-check"><input type="checkbox" data-bg-edge> <span><?php esc_html_e( 'Only the background', 'looma' ); ?> <small><?php esc_html_e( '(keeps the same colour inside your design)', 'looma' ); ?></small></span></label>
								<button type="button" class="btn btn-dark btn-sm ds-bg-dl" data-bg="download"><?php esc_html_e( '↓ Download PNG · full size', 'looma' ); ?></button>
							</div>
						</div>
					</div>
					<p class="ds-quality" data-quality></p>
				</section>

				<section class="ds-card">
					<h2 class="ds-h"><span>4</span> <?php esc_html_e( 'Print type & quantity', 'looma' ); ?></h2>
					<div class="ds-methods" data-methods></div>
					<p class="ds-label"><?php esc_html_e( 'Sizes', 'looma' ); ?> <em class="ds-qty-total" data-qty-total></em></p>
					<div class="ds-sizes" data-sizes></div>
					<div class="ds-hint" data-q-hint hidden></div>
					<label class="ds-check"><input type="checkbox" data-label-opt checked> <span><?php esc_html_e( 'Add my brand neck label', 'looma' ); ?> <small data-label-note></small></span></label>
					<button type="button" class="btn btn-outline btn-block ds-add-product" data-add-product>＋ <?php esc_html_e( 'Save & add another product', 'looma' ); ?></button>
					<p class="ds-muted ds-add-note"><?php esc_html_e( 'Ordering more than one style? Save this product, then design the next one (e.g. full sleeve or regular fit). Everything is quoted and sent together.', 'looma' ); ?></p>
				</section>

				<section class="ds-card ds-quote" id="ds-quote">
					<h2 class="ds-h"><span>5</span> <?php esc_html_e( 'Your quote', 'looma' ); ?></h2>
					<div class="ds-order" data-order hidden>
						<p class="ds-order-h"><?php esc_html_e( 'Products in this order', 'looma' ); ?></p>
						<ul class="ds-order-list" data-order-list></ul>
						<p class="ds-order-h" data-cur-h><?php esc_html_e( 'Product you are designing', 'looma' ); ?></p>
					</div>
					<ul class="ds-q-lines" data-q-lines></ul>
					<dl class="ds-q-totals">
						<div><dt data-q-per-label><?php esc_html_e( 'Per piece', 'looma' ); ?></dt><dd data-q-per>—</dd></div>
						<div><dt data-q-sub-label><?php esc_html_e( 'Subtotal', 'looma' ); ?></dt><dd data-q-sub>—</dd></div>
						<div><dt><?php esc_html_e( 'GST (5%)', 'looma' ); ?></dt><dd data-q-gst>—</dd></div>
						<div class="grand"><dt><?php esc_html_e( 'Estimated total', 'looma' ); ?></dt><dd data-q-total>—</dd></div>
					</dl>
					<div class="ds-hint" data-q-hint hidden></div>
					<p class="ds-ship"><?php looma_the_icon( 'truck' ); ?> <?php esc_html_e( 'Shipping charges extra, as per actual weight & location.', 'looma' ); ?></p>

					<div class="ds-send">
						<button type="button" class="btn btn-wa btn-lg btn-block" data-send><?php looma_the_icon( 'whatsapp' ); ?> <span data-send-label><?php esc_html_e( 'Send design on WhatsApp', 'looma' ); ?></span></button>
						<p class="ds-send-note"><?php esc_html_e( 'Your WhatsApp opens a chat with Looma Apparels with all your details; then send your mockup images to the same chat in one tap.', 'looma' ); ?></p>
						<div class="ds-send-status" data-status hidden></div>
						<button type="button" class="ds-reset" data-reset><?php esc_html_e( 'Start a new design', 'looma' ); ?></button>
					</div>
				</section>
			</div>
		</div>

		<div class="ds-mobile-bar" data-mbar>
			<div><small data-m-info></small><strong data-m-total>₹0</strong></div>
			<a class="btn btn-wa btn-sm" href="#ds-quote"><?php esc_html_e( 'Quote & send', 'looma' ); ?></a>
		</div>
	</section>

	<section class="section section-tint">
		<div class="container">
			<div class="section-head center"><div><p class="eyebrow"><?php esc_html_e( 'Good to know', 'looma' ); ?></p><h2 class="section-title"><?php esc_html_e( 'Design Studio tips', 'looma' ); ?></h2></div></div>
			<div class="usp-grid">
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'layers' ); ?></span><h3><?php esc_html_e( 'Best file types', 'looma' ); ?></h3><p><?php esc_html_e( 'Transparent PNG, SVG or a vector PDF at 300 DPI. A 12" wide print needs about 3600 px. For PDFs the first page is used.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'ruler' ); ?></span><h3><?php esc_html_e( 'Real print sizes', 'looma' ); ?></h3><p><?php esc_html_e( 'Sizes are measured on a size M tee. We scale prints sensibly for other sizes.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'printer' ); ?></span><h3><?php esc_html_e( 'Price by print size', 'looma' ); ?></h3><p><?php esc_html_e( 'Each print is priced as Logo, A6, A5, A4, A3 or A2 DTF based on its actual size — or embroidery by stitches.', 'looma' ); ?></p></div>
				<div class="usp"><span class="usp-icon"><?php looma_the_icon( 'shield' ); ?></span><h3><?php esc_html_e( 'We check every design', 'looma' ); ?></h3><p><?php esc_html_e( 'Our team reviews your artwork and confirms the final mockup and price before printing.', 'looma' ); ?></p></div>
			</div>
		</div>
	</section>
</main>
<?php
get_footer();
