<?php
// Fausse Banque du Canada : un taux fixe, pour que les rapports donnent toujours les mêmes montants.
header('Content-Type: application/json');
echo json_encode(['observations' => [['d' => '2026-11-02', 'FXUSDCAD' => ['v' => '1.3700']]]]);
