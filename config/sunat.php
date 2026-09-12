<?php

return [
    'production' => env('SUNAT_PRODUCTION', false),
    'ruc' => env('SUNAT_RUC'),
    'user' => env('SUNAT_USER'),
    'password' => env('SUNAT_PASSWORD'),
    'certificate_name' => env('SUNAT_CERTIFICATE_NAME', 'certificate.pem'),
    'endpoints' => [
        'soap' => env('SUNAT_PRODUCTION', false) ? 'https://e-facturacion.sunat.gob.pe/ol-ti-itcpfegem/billService' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService',
        'rest_auth' => env('SUNAT_PRODUCTION', false) ? 'https://api-seguridad.sunat.gob.pe/v1' : 'https://gre-test.nubefact.com/v1',            
        'rest_cpe' => env('SUNAT_PRODUCTION', false) ? 'https://api.sunat.gob.pe/v1' : 'https://gre-test.nubefact.com/v1',
    ],
];
