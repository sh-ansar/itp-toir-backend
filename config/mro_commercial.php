<?php
return [
 'frontend_url'=>env('TOIR_FRONTEND_URL','https://platform.itpmining.kz/mro'),
 'subscriptions_enabled'=>env('MRO_SUBSCRIPTIONS_ENABLED',true),
 'subscription_enforcement_enabled'=>env('MRO_SUBSCRIPTION_ENFORCEMENT_ENABLED',false),
 'billing_enabled'=>env('MRO_BILLING_ENABLED',false),
 'payment_collection_enabled'=>env('MRO_PAYMENT_COLLECTION_ENABLED',false),
 'invoice_issuance_enabled'=>env('MRO_INVOICE_ISSUANCE_ENABLED',false),
 'legal_publication_enabled'=>env('MRO_LEGAL_PUBLICATION_ENABLED',false),
];
