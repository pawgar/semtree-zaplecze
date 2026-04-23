            </div><!-- /.container-xl -->
        </div><!-- /.page-body -->

        <!-- Footer -->
        <footer class="footer footer-transparent d-print-none">
            <div class="container-xl">
                <div class="row text-center align-items-center flex-row-reverse">
                    <div class="col-lg-auto ms-lg-auto">
                        <ul class="list-inline list-inline-dots mb-0">
                            <li class="list-inline-item"><a href="#" data-bs-toggle="modal" data-bs-target="#changelogModal" class="link-secondary">Changelog</a></li>
                            <li class="list-inline-item"><a href="https://semtree.pl" target="_blank" class="link-secondary" rel="noopener">Semtree.pl</a></li>
                        </ul>
                    </div>
                    <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                        <ul class="list-inline list-inline-dots mb-0">
                            <li class="list-inline-item">
                                Copyright &copy; <?= date('Y') ?> <a href="#" class="link-secondary">Semtree Zaplecze</a>.
                                Wszystkie prawa zastrzeżone.
                            </li>
                            <li class="list-inline-item">
                                <span class="text-secondary">v2.6</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </footer>
    </div><!-- /.page-wrapper -->
</div><!-- /.page -->

<!-- Bootstrap 5 bundle (Modal, Dropdown, Tooltip, Collapse) — required before Tabler -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<!-- Tabler JS (extends Bootstrap with custom plugins) -->
<script src="assets/vendor/tabler/js/tabler.min.js"></script>
<!-- ApexCharts (lazy — only pages that use it) -->
<script src="assets/vendor/tabler/libs/apexcharts/dist/apexcharts.min.js" defer></script>
<!-- Project JS -->
<script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
