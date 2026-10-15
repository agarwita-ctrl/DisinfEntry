<?php declare(strict_types=1); ?>
    </main>

    <footer class="app-footer">
      <span>&copy; <?= date('Y') ?> <?= e(setting('system_name', 'DisinfEntry')) ?> — <?= e(setting('farm_name', 'Poultry Farm')) ?></span>
      <span class="text-muted small">Smart Disinfection &amp; Entry Monitoring System</span>
    </footer>
  </div>
</div>

<!-- Toast container -->
<div class="toast-container position-fixed top-0 end-0 p-3" id="toastArea" style="z-index:1090"></div>

<!-- Alert popup (high temperature / denied entry) -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-0" id="alertModalHeader">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i><span id="alertModalTitle">Alert</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="alert-temp display-4 fw-bold mb-2" id="alertModalTemp"></div>
        <p class="mb-0 fs-6" id="alertModalBody"></p>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Dismiss</button>
        <a href="<?= url('live.php') ?>" class="btn btn-primary">Open Live Monitor</a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js" integrity="sha384-Udt767MMeKelGRBxaCfxX88YDLbViYdQ7T/gkRoB197Jf+OviZ+lsaRAOpS/MIjf" crossorigin="anonymous"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js" integrity="sha384-PgPBH0hy6DTJwu7pTf6bkRqPlf/+pjUBExpr/eIfzszlGYFlF9Wi9VTAJODPhgCO" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
<script src="<?= asset_url('assets/js/app.js') ?>"></script>
<?php foreach ($PAGE_SCRIPTS ?? [] as $script): ?>
<script src="<?= asset_url($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
