<?php
// Walkthrough dock and spotlight runner
if (!isset($topic) || empty($topic['steps'])) {
    return;
}
$stepCount = count($topic['steps']);
$currentStep = max(0, min($stepCount - 1, (int)($initialStep ?? 0)));
$csrfToken = csrf_token();
$helpPostUrl = app_url('components/auth/help.php');
$pathKey = $topic['path_key'] ?? 'get_oriented';
$allTopics = \App\Guidance\WorkspaceWalkthroughService::paths(current_role())[$pathKey]['topics'] ?? [];
$completedTopics = $walkthroughProgress['completed_topics'] ?? [];
?>
<aside id="guidance-dock" class="guidance-dock" role="region" aria-label="Interactive guidance walkthrough">
    <div class="guidance-dock-header">
        <div class="guidance-dock-meta">
            <span class="guidance-path-badge"><?= htmlspecialchars($topic['path_title'] ?? 'Get oriented') ?></span>
            <span class="guidance-step-count" aria-live="polite">Step <?= $currentStep + 1 ?> of <?= $stepCount ?></span>
        </div>
        <button type="button" class="btn btn-secondary guidance-btn-exit" id="guidance-btn-exit">Exit</button>
    </div>

    <details class="guidance-topic-checklist">
        <summary>Topic checklist (<?= count($completedTopics) ?> of <?= count($allTopics) ?> completed)</summary>
        <ul class="guidance-checklist-items">
            <?php foreach ($allTopics as $tId => $tDef):
                $isDone = in_array($tId, $completedTopics, true);
                $isCurrent = $tId === $topic['id'];
            ?>
            <li class="<?= $isCurrent ? 'is-current' : ($isDone ? 'is-done' : '') ?>">
                <span class="checklist-status-icon" aria-hidden="true"><?= $isDone ? '✓' : ($isCurrent ? '●' : '○') ?></span>
                <span><?= htmlspecialchars($tDef['title']) ?></span>
                <?php if ($isCurrent): ?><span class="checklist-badge-active">(current)</span><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </details>

    <div class="guidance-dock-body" id="guidance-step-content">
        <h3 id="guidance-step-title" class="guidance-step-title"><?= htmlspecialchars($topic['steps'][$currentStep]['action']) ?></h3>
        <p id="guidance-step-desc" class="guidance-step-desc"><?= htmlspecialchars($topic['steps'][$currentStep]['description']) ?></p>
        <?php if (!empty($topic['steps'][$currentStep]['learn_more'])): ?>
            <details class="guidance-learn-more" id="guidance-learn-more">
                <summary>Learn more</summary>
                <p><?= htmlspecialchars($topic['steps'][$currentStep]['learn_more']) ?></p>
            </details>
        <?php endif; ?>
    </div>

    <div class="guidance-dock-footer">
        <button type="button" class="btn btn-secondary guidance-btn-back" id="guidance-btn-back" <?= $currentStep === 0 ? 'disabled' : '' ?>>Back</button>
        <button type="button" class="btn btn-primary guidance-btn-next" id="guidance-btn-next"><?= $currentStep === $stepCount - 1 ? 'Finish guidance' : 'Next' ?></button>
    </div>

    <div id="guidance-completion-status" class="guidance-completion-status u-hidden" role="status" aria-live="polite">
        <div class="guidance-completion-header">
            <span class="guidance-checkmark" aria-hidden="true">✓</span>
            <div>
                <strong>Guidance finished.</strong>
                <p><?= htmlspecialchars($topic['title']) ?> walkthrough completed. Your operational <?= ($topic['id'] === 'store_settings') ? 'settings' : 'records' ?> were not modified.</p>
            </div>
        </div>
        <button type="button" class="btn btn-primary u-mt-1" id="guidance-btn-done">Close</button>
    </div>
</aside>

<script>
(function() {
    const steps = <?= json_encode($topic['steps'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const topicId = <?= json_encode($topic['id']) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const postUrl = <?= json_encode($helpPostUrl) ?>;
    let currentStep = <?= (int)$currentStep ?>;

    const dock = document.getElementById('guidance-dock');
    if (!dock) return;

    const stepCountEl = dock.querySelector('.guidance-step-count');
    const titleEl = document.getElementById('guidance-step-title');
    const descEl = document.getElementById('guidance-step-desc');
    const learnMoreEl = document.getElementById('guidance-learn-more');
    const backBtn = document.getElementById('guidance-btn-back');
    const nextBtn = document.getElementById('guidance-btn-next');
    const exitBtn = document.getElementById('guidance-btn-exit');
    const completionEl = document.getElementById('guidance-completion-status');
    const doneBtn = document.getElementById('guidance-btn-done');
    const bodyEl = document.getElementById('guidance-step-content');
    const footerEl = dock.querySelector('.guidance-dock-footer');

    function applySpotlight(stepIndex) {
        document.querySelectorAll('.guidance-spotlight-target').forEach(el => {
            el.classList.remove('guidance-spotlight-target');
        });
        const step = steps[stepIndex];
        if (!step || !step.target) return;
        const targetEl = document.querySelector(step.target);
        if (targetEl) {
            targetEl.classList.add('guidance-spotlight-target');
            targetEl.scrollIntoView({behavior: 'smooth', block: 'center'});
        }
    }

    function renderStep(index) {
        currentStep = index;
        const step = steps[index];
        if (!step) return;

        stepCountEl.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;
        titleEl.textContent = step.action;
        descEl.textContent = step.description;

        if (learnMoreEl) {
            if (step.learn_more) {
                learnMoreEl.style.display = '';
                const p = learnMoreEl.querySelector('p');
                if (p) p.textContent = step.learn_more;
                learnMoreEl.open = false;
            } else {
                learnMoreEl.style.display = 'none';
            }
        }

        backBtn.disabled = (index === 0);
        nextBtn.textContent = (index === steps.length - 1) ? 'Finish guidance' : 'Next';

        applySpotlight(index);
        titleEl.focus();
    }

    function saveProgress(action, stepIndex) {
        try {
            fetch(postUrl, {
                method: 'POST',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({
                    csrf_token: csrfToken,
                    walkthrough_action: action,
                    walkthrough_topic: topicId,
                    walkthrough_step: String(stepIndex)
                }).toString()
            }).catch(function(e) {
                console.error('Walkthrough save error:', e);
            });
        } catch(e) {
            console.error('Walkthrough network error:', e);
        }
    }

    function exitWalkthrough() {
        saveProgress('exit', currentStep);
        document.querySelectorAll('.guidance-spotlight-target').forEach(el => {
            el.classList.remove('guidance-spotlight-target');
        });
        const currentTarget = steps[currentStep] ? document.querySelector(steps[currentStep].target) : null;
        dock.remove();
        if (currentTarget) currentTarget.focus();
    }

    backBtn.addEventListener('click', function() {
        if (currentStep > 0) {
            renderStep(currentStep - 1);
            saveProgress('step', currentStep);
        }
    });

    nextBtn.addEventListener('click', function() {
        if (currentStep < steps.length - 1) {
            renderStep(currentStep + 1);
            saveProgress('step', currentStep);
        } else {
            // Last step: complete!
            saveProgress('complete', currentStep);
            document.querySelectorAll('.guidance-spotlight-target').forEach(el => {
                el.classList.remove('guidance-spotlight-target');
            });
            bodyEl.style.display = 'none';
            footerEl.style.display = 'none';
            completionEl.classList.remove('u-hidden');
            doneBtn.focus();
        }
    });

    exitBtn.addEventListener('click', exitWalkthrough);

    doneBtn.addEventListener('click', function() {
        dock.remove();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.body.contains(dock)) {
            exitWalkthrough();
        }
    });

    // Initial spotlight
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            applySpotlight(currentStep);
        });
    } else {
        applySpotlight(currentStep);
    }
})();
</script>
