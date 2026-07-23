<?php

use Arshwell\Monolith\Web;

/** @var int $version */
/** @var string|null $error */
/** @var array{sourceDirectory: string, isClean: bool, rows: list<array<string, mixed>}>|null $catalog */
?>
<div class="container py-4">
    <h1 class="h3 mb-3">Template demo — v<?= (int) $version ?></h1>

    <?php if ($error !== null) { ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php } elseif ($catalog !== null) { ?>
        <p class="text-muted mb-1">Source: <code><?= htmlspecialchars($catalog['sourceDirectory'], ENT_QUOTES, 'UTF-8') ?></code></p>
        <p class="text-muted mb-3">Demo player pool: <?= (int) ($catalog['playerPoolSize'] ?? 0) ?> (static photos only)</p>

        <?php if ($catalog['rows'] === []) { ?>
            <div class="alert alert-warning" role="alert">No template JSON files found in <?= htmlspecialchars($catalog['sourceDirectory'], ENT_QUOTES, 'UTF-8') ?>.</div>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Players</th>
                            <th>Partners</th>
                            <th>Repeat</th>
                            <th>Courts</th>
                            <th>Fixed</th>
                            <th>Eligible</th>
                            <th>Usable</th>
                            <th>Demo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($catalog['rows'] as $row) { ?>
                            <tr>
                                <td><?= (int) $row['players'] ?></td>
                                <td><?= (int) $row['partners'] ?></td>
                                <td><?= (int) $row['repeat'] ?></td>
                                <td><?= (int) $row['courts'] ?></td>
                                <td><?= !empty($row['fixedTeams']) ? 'yes' : 'no' ?></td>
                                <td><?= htmlspecialchars((string) $row['eligible'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $row['usable'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($row['openQuery'] !== null) { ?>
                                        <a href="<?= htmlspecialchars(
                                            Web::url('site.matches.1st-step-generate', null, null, 0, $row['openQuery']),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>" target="_blank" rel="noopener">Open</a>
                                    <?php } else { ?>
                                        <?= htmlspecialchars((string) ($row['demoReason'] ?? 'unavailable'), ENT_QUOTES, 'UTF-8') ?>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    <?php } ?>
</div>
