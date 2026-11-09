<?php
/**
 * Style guide: every shared component on one page, for design approval.
 * Developer page: only opens when 'debug' is true in config/config.php.
 */
require __DIR__ . '/includes/init.php';
require_debug_mode();

$page_title = 'Style guide';
$active_nav = '';
$page_css   = ['css/styleguide.css'];

$swatches = [
    ['Primary',       '--color-primary',       '#17325c'],
    ['Primary dark',  '--color-primary-dark',  '#0f2342'],
    ['Primary light', '--color-primary-light', '#e7edf6'],
    ['Accent (brass)','--color-accent',        '#b8862b'],
    ['Accent light',  '--color-accent-light',  '#f8f0dc'],
    ['Text',          '--color-text',          '#1d2733'],
    ['Muted text',    '--color-text-muted',    '#526071'],
    ['Background',    '--color-bg',            '#f4f6f9'],
    ['Border',        '--color-border',        '#d3dae3'],
    ['Success',       '--color-success',       '#1c6b45'],
    ['Warning',       '--color-warning',       '#7a5200'],
    ['Danger',        '--color-danger',        '#a7261f'],
    ['Info',          '--color-info',          '#1a5a9e'],
];

// Make the pagination demo live: ?page=N changes the current page.
$demo_page = paginate(95, 10);

$validator_demo = [
    'Mobile "0917 123"'            => validate_ph_mobile('0917 123'),
    'Mobile "+63 917 123 4567"'    => validate_ph_mobile('+63 917 123 4567'),
    'Student no. "2023-00123"'     => validate_student_no('2023-00123'),
    'Student no. "ABC"'            => validate_student_no('ABC'),
    'Quantity "9" (1 to 5)'        => validate_int_range('9', 'Quantity', 1, 5),
];

require __DIR__ . '/includes/public_header.php';
?>
<div class="container section">
    <div class="page-header">
        <h1>Style guide</h1>
        <p>Shared components for every page. Approve the look here before the pages are built.</p>
    </div>

    <section class="sg-section">
        <h2>Colors</h2>
        <div class="sg-swatches">
            <?php foreach ($swatches as [$name, $var, $hex]): ?>
            <div class="sg-swatch">
                <div class="sg-swatch__chip" style="background: var(<?= e($var) ?>)"></div>
                <div class="sg-swatch__meta"><strong><?= e($name) ?></strong><code><?= e($hex) ?></code></div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="sg-section">
        <h2>Typography</h2>
        <div class="sg-demo sg-type">
            <h1>Heading 1: Uniforms and togas</h1>
            <h2>Heading 2: Reserve before the semester starts</h2>
            <h3>Heading 3: Primary boys polo</h3>
            <h4>Heading 4: Sizes and prices</h4>
            <p>Body text uses the system font so it loads instantly, even on an offline school network. Headings use a serif stack for a printed-school feel. <a href="#">This is a link.</a></p>
            <p class="text-muted">Muted text for hints and secondary details.</p>
            <p class="text-sm">Small text for table notes and footers.</p>
        </div>
    </section>

    <section class="sg-section">
        <h2>Buttons</h2>
        <div class="sg-demo cluster">
            <button class="btn" type="button">Primary</button>
            <button class="btn btn--secondary" type="button">Secondary</button>
            <button class="btn btn--accent" type="button">Accent</button>
            <button class="btn btn--danger" type="button">Danger</button>
            <button class="btn btn--ghost" type="button">Ghost</button>
            <button class="btn" type="button" disabled>Disabled</button>
            <a class="btn btn--sm" href="#">Small link button</a>
            <button class="btn btn--lg" type="button">Large</button>
        </div>
        <div class="sg-demo"><button class="btn btn--block" type="button">Full-width button (mobile forms)</button></div>
    </section>

    <section class="sg-section">
        <h2>Forms</h2>
        <form class="sg-demo" onsubmit="return false" novalidate>
            <?= csrf_field() ?>
            <div class="form-row form-row--2">
                <div class="form-group">
                    <label class="form-label" for="sg-name">Full name <span class="req" aria-hidden="true">*</span></label>
                    <input class="form-control" id="sg-name" type="text" placeholder="Juan Dela Cruz">
                    <p class="form-hint">As it appears on the student ID.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="sg-mobile">Mobile number</label>
                    <input class="form-control is-invalid" id="sg-mobile" type="tel" value="0917 123" aria-describedby="err-sg-mobile" aria-invalid="true">
                    <p class="form-error" id="err-sg-mobile"><?= e(validate_ph_mobile('0917 123')) ?></p>
                </div>
            </div>
            <div class="form-row form-row--2">
                <div class="form-group">
                    <label class="form-label" for="sg-size">Size</label>
                    <select class="form-select" id="sg-size"><option>Select a size</option><option>Small</option><option>Medium</option></select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="sg-qty">Quantity</label>
                    <input class="form-control" id="sg-qty" type="number" min="1" max="5" value="1">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="sg-notes">Notes</label>
                <textarea class="form-textarea" id="sg-notes"></textarea>
            </div>
            <label class="form-check"><input type="checkbox"> <span>I understand payment is made at the cashier.</span></label>
            <button class="btn" type="button">Submit request</button>
        </form>
        <div class="sg-demo">
            <p><strong>Validator samples</strong> (null means valid)</p>
            <ul>
                <?php foreach ($validator_demo as $label => $result): ?>
                <li><?= e($label) ?>: <?= $result === null ? '<span class="badge badge--in">valid</span>' : e($result) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="sg-section">
        <h2>Cards and grid</h2>
        <div class="grid grid--3">
            <div class="card card--accent">
                <div class="card__header">Primary Boys Polo</div>
                <div class="card__body"><p><?= e(money(255)) ?> per piece</p><?= stock_badge(15) ?></div>
                <div class="card__footer"><a class="btn btn--sm" href="#">Reserve</a></div>
            </div>
            <div class="card card--accent">
                <div class="card__header">Primary Girls Blouse</div>
                <div class="card__body"><p><?= e(money(275)) ?> per piece</p><?= stock_badge(0) ?></div>
                <div class="card__footer"><a class="btn btn--sm btn--secondary" href="#">Join waitlist</a></div>
            </div>
            <a class="card" href="#">
                <div class="card__body"><h3 class="card__title">Whole card is a link</h3><p class="mb-0 text-muted">Use for category tiles.</p></div>
            </a>
        </div>
    </section>

    <section class="sg-section">
        <h2>Table</h2>
        <div class="table-responsive">
            <table class="table table--striped">
                <thead><tr><th>Order slip no.</th><th>Customer</th><th>Status</th><th class="num">Total</th><th>Date</th></tr></thead>
                <tbody>
                    <tr><td>OS-2026-0001</td><td>Maria Santos</td><td><?= status_badge('requested') ?></td><td class="num"><?= e(money(510)) ?></td><td><?= e(format_date('2026-11-06')) ?></td></tr>
                    <tr><td>OS-2026-0002</td><td>Carlo Mendoza</td><td><?= status_badge('paid') ?></td><td class="num"><?= e(money(330)) ?></td><td><?= e(format_date('2026-11-05')) ?></td></tr>
                    <tr><td>OS-2026-0003</td><td>Grace Lim</td><td><?= status_badge('claimed') ?></td><td class="num"><?= e(money(1250.5)) ?></td><td><?= e(format_datetime('2026-11-04 14:30:00')) ?></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section class="sg-section">
        <h2>Badges</h2>
        <div class="sg-demo">
            <p><strong>Order statuses</strong></p>
            <div class="cluster"><?php foreach (array_keys(order_statuses()) as $s) { echo status_badge($s); } ?></div>
            <p class="mt-4"><strong>Stock</strong></p>
            <div class="cluster"><?= stock_badge(20) ?> <?= stock_badge(3) ?> <?= stock_badge(0) ?></div>
        </div>
    </section>

    <section class="sg-section">
        <h2>Alerts</h2>
        <div class="alert alert--success"><span class="alert__text"><strong>Reservation received.</strong> Keep your reference number to track it.</span></div>
        <div class="alert alert--info"><span class="alert__text">Payment is made at the cashier, then bring the proof of payment here.</span></div>
        <div class="alert alert--warning"><span class="alert__text">Only 2 left in this size. Reserve soon.</span></div>
        <div class="alert alert--error"><span class="alert__text"><strong>Could not save.</strong> Check the highlighted fields and try again.</span>
            <button type="button" class="alert__close" data-dismiss="alert" aria-label="Dismiss">&times;</button></div>
    </section>

    <section class="sg-section">
        <h2>Tabs</h2>
        <div data-tabs>
            <div class="tab-list" role="tablist">
                <button class="tab" role="tab" id="tab-1" aria-controls="panel-1" aria-selected="true">School uniforms</button>
                <button class="tab" role="tab" id="tab-2" aria-controls="panel-2" aria-selected="false">P.E. uniforms</button>
                <button class="tab" role="tab" id="tab-3" aria-controls="panel-3" aria-selected="false">Togas</button>
            </div>
            <div class="tab-panel" role="tabpanel" id="panel-1" aria-labelledby="tab-1"><p>Regular uniforms, primary to tertiary.</p></div>
            <div class="tab-panel" role="tabpanel" id="panel-2" aria-labelledby="tab-2" hidden><p>Physical education shirts and pants.</p></div>
            <div class="tab-panel" role="tabpanel" id="panel-3" aria-labelledby="tab-3" hidden><p>Graduation toga rentals.</p></div>
        </div>
    </section>

    <section class="sg-section">
        <h2>Pagination</h2>
        <p class="text-muted">Showing <?= (int)$demo_page['from'] ?> to <?= (int)$demo_page['to'] ?> of <?= (int)$demo_page['total'] ?> (click to try).</p>
        <?= pagination_links($demo_page, 'styleguide.php') ?>
    </section>

    <section class="sg-section">
        <h2>Print</h2>
        <p>Print styles hide the header, footer and buttons and use plain black-and-white tables, ready for the order slip.</p>
        <button class="btn btn--secondary" type="button" data-print>Print this page</button>
    </section>
</div>
<?php require __DIR__ . '/includes/public_footer.php'; ?>
