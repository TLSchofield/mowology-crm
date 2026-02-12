<?php
/**
 * FAQ Block Form
 *
 * Config fields: title, description, faqs[] (question, answer)
 *
 * @var array $config   Current block config values
 * @var int   $blockIndex  Unique index for form field names
 */

$title       = $config['title'] ?? '';
$description = $config['description'] ?? '';
$faqs        = $config['faqs'] ?? [];
?>

<div class="faq-block-form">
    <div class="form-group">
        <label for="faq-title-<?= $blockIndex ?>">Section Title</label>
        <input type="text"
               class="form-control"
               id="faq-title-<?= $blockIndex ?>"
               name="blocks[<?= $blockIndex ?>][config][title]"
               value="<?= h($title) ?>"
               placeholder="e.g. Frequently Asked Questions">
    </div>

    <div class="form-group">
        <label for="faq-description-<?= $blockIndex ?>">Section Description</label>
        <textarea class="form-control"
                  id="faq-description-<?= $blockIndex ?>"
                  name="blocks[<?= $blockIndex ?>][config][description]"
                  rows="2"
                  placeholder="Optional intro text above the FAQ items."><?= h($description) ?></textarea>
    </div>

    <hr>
    <h6 class="text-muted mb-3">FAQ Items</h6>

    <div class="faq-items-container-<?= $blockIndex ?>">
        <?php if (!empty($faqs)): ?>
            <?php foreach ($faqs as $i => $faq): ?>
                <div class="card mb-2 faq-item" data-index="<?= $i ?>">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-muted">FAQ #<?= $i + 1 ?></strong>
                            <button type="button" class="btn btn-sm btn-outline-danger remove-faq-btn">&times; Remove</button>
                        </div>
                        <div class="form-group mb-2">
                            <label>Question</label>
                            <input type="text"
                                   class="form-control form-control-sm"
                                   name="blocks[<?= $blockIndex ?>][config][faqs][<?= $i ?>][question]"
                                   value="<?= h($faq['question'] ?? '') ?>"
                                   placeholder="e.g. How often should I water my lawn?">
                        </div>
                        <div class="form-group mb-0">
                            <label>Answer</label>
                            <textarea class="form-control form-control-sm"
                                      name="blocks[<?= $blockIndex ?>][config][faqs][<?= $i ?>][answer]"
                                      rows="3"
                                      placeholder="Provide a clear, helpful answer."><?= h($faq['answer'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="btn btn-sm btn-outline-success add-faq-btn-<?= $blockIndex ?>">
        + Add FAQ
    </button>
</div>

<script>
(function() {
    var blockIndex = <?= (int)$blockIndex ?>;
    var container = document.querySelector('.faq-items-container-' + blockIndex);
    var addBtn = document.querySelector('.add-faq-btn-' + blockIndex);
    var itemCount = container.querySelectorAll('.faq-item').length;

    addBtn.addEventListener('click', function() {
        var idx = itemCount++;
        var card = document.createElement('div');
        card.className = 'card mb-2 faq-item';
        card.setAttribute('data-index', idx);
        card.innerHTML =
            '<div class="card-body p-3">' +
                '<div class="d-flex justify-content-between align-items-center mb-2">' +
                    '<strong class="text-muted">FAQ #' + (idx + 1) + '</strong>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-faq-btn">&times; Remove</button>' +
                '</div>' +
                '<div class="form-group mb-2">' +
                    '<label>Question</label>' +
                    '<input type="text" class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][faqs][' + idx + '][question]" placeholder="e.g. How often should I water my lawn?">' +
                '</div>' +
                '<div class="form-group mb-0">' +
                    '<label>Answer</label>' +
                    '<textarea class="form-control form-control-sm" name="blocks[' + blockIndex + '][config][faqs][' + idx + '][answer]" rows="3" placeholder="Provide a clear, helpful answer."></textarea>' +
                '</div>' +
            '</div>';
        container.appendChild(card);
    });

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-faq-btn')) {
            e.target.closest('.faq-item').remove();
        }
    });
})();
</script>
