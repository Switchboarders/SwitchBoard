<?php
// Les données comptables des comptes inventés, pour que les rapports donnent toujours le même résultat.
// Les champs reprennent ceux que le code lit, y compris « ammount », écrit ainsi par voip.ms.
function ligneCdr(string $date, string $destination, string $description, string $reponse, string $duree, int $secondes, string $total, string $journal = ''): array {
	return ['date' => $date, 'callerid' => '5145559999', 'destination' => $destination, 'description' => $description,
		'disposition' => $reponse, 'duration' => $duree, 'seconds' => (string)$secondes, 'total' => $total, 'call_logs' => $journal];
}

return [
	'banc-autonome' => [
		'solde' => '25.5000',
		'dids' => [['did' => '5145550101', 'description' => 'Ligne autonome']],
		'cdr' => [
			ligneCdr('2026-10-02 10:00:00', '5145550101', 'Inbound DID', 'ANSWERED', '00:00:20', 20, '0.0100'),
			ligneCdr('2026-10-03 23:15:00', '5145550101', 'Inbound DID', 'ANSWERED', '00:00:45', 45, '0.0200'),
			ligneCdr('2026-10-04 02:30:00', '5145550101', 'Inbound DID', 'ANSWERED', '00:02:00', 120, '0.0500'),
			ligneCdr('2026-10-04 07:30:00', '5145550101', 'Inbound DID', 'NO ANSWER', '00:00:00', 0, '0.0000'),
			ligneCdr('2026-10-05 12:00:00', '5145550101', 'Inbound DID', 'BUSY', '00:00:00', 0, '0.0000'),
			ligneCdr('2026-10-04 02:30:00', '5145550111', 'Outbound Call', 'ANSWERED', '00:01:58', 118, '0.0300', 'Call Forwarding'),
		],
		'transactions' => [
			['type' => 'DID5145550101', 'description' => 'Location', 'ammount' => '-0.8500'],
			['type' => 'CC', 'description' => 'Recharge', 'ammount' => '25.0000'],
			['type' => 'SMS', 'description' => 'Textos', 'ammount' => '-0.3000'],
		],
	],
	'banc-region' => [
		'solde' => '142.2500',
		'dids' => [
			['did' => '5145550102', 'description' => 'Ligne membre 1'],
			['did' => '5145550103', 'description' => 'Ligne membre 2'],
		],
		'cdr' => [
			ligneCdr('2026-10-06 09:00:00', '5145550102', 'Inbound DID', 'ANSWERED', '00:01:30', 90, '0.0400'),
			ligneCdr('2026-10-07 09:00:00', '5145550103', 'Inbound DID', 'ANSWERED', '00:03:00', 180, '0.0800'),
			ligneCdr('2026-10-08 09:00:00', '5145550103', 'Inbound DID', 'FAILED', '00:00:00', 0, '0.0000'),
		],
		'transactions' => [
			['type' => 'DID5145550102', 'description' => 'Location', 'ammount' => '-0.8500'],
			['type' => 'DID5145550103', 'description' => 'Location', 'ammount' => '-0.8500'],
			['type' => 'FAX', 'description' => 'Frais du compte', 'ammount' => '-1.0000'],
		],
	],
	'banc-laa' => [
		'solde' => '310.0000',
		'dids' => [],
		'cdr' => [
			ligneCdr('2026-10-09 09:00:00', '5145550187', 'Outbound Call', 'ANSWERED', '00:01:00', 60, '0.0250'),
			ligneCdr('2026-10-09 10:00:00', '5145550187', 'Outbound Call', 'ANSWERED', '00:02:00', 120, '0.0500'),
			ligneCdr('2026-10-10 11:00:00', '15145550188', 'Outbound Call', 'ANSWERED', '00:03:00', 180, '0.0750'),
			ligneCdr('2026-10-11 12:00:00', '5145550199', 'Outbound Call', 'ANSWERED', '00:01:00', 60, '0.0250'),
		],
		'transactions' => [],
	],
];
