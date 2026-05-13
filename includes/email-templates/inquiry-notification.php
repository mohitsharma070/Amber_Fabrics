<?php
$subject = 'New Inquiry Received #' . (int) ($data['id'] ?? 0);
$lines = [
    'A new inquiry was submitted.',
    '',
    'ID: ' . ((int) ($data['id'] ?? 0)),
    'Name: ' . ((string) ($data['name'] ?? '')),
    'Email: ' . ((string) ($data['email'] ?? '')),
    'Country: ' . ((string) ($data['country'] ?? '')),
    'Fabric: ' . ((string) ($data['fabric_type'] ?? '')),
    'Quantity: ' . ((string) ($data['quantity'] ?? '')),
    'Meters: ' . ((string) ($data['meters'] ?? '')),
    'Incoterm: ' . ((string) ($data['incoterm'] ?? '')),
    'Destination: ' . ((string) ($data['destination'] ?? '')),
    'Pin Code: ' . ((string) ($data['pincode'] ?? '')),
    'Timeline: ' . ((string) ($data['timeline'] ?? '')),
    '',
    'Message:',
    ((string) ($data['message'] ?? '')),
];
return ['subject' => $subject, 'body' => implode("\\r\\n", $lines)];
