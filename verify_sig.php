<?php
$params = array (
  'SAMLRequest' => 'fZJBb6MwEIXv+yuQ72AgpAUrUGUbVY3U3UUN3cNeVsYZEkvGph6T3f33C04jtYfm6PGM3zfveXX3t1fBCSxKo0uSRDEJQAuzl/pQkpfmIczJXfVlhbxXA1uP7qif4XUEdME0qJH5i5KMVjPDUSLTvAdkTrDd+tsTS6OYDdY4I4wiwXZTkt9w0xaLRdul7b7I8iIvALIF5Msu6xa3RZeR4OcFJ51xtogjbDU6rt1UitNlmMRhmjZJzrKEZctfJNhMQFJz56eOzg2M0uQ2jZKbKI6ymOVxnlALXPVIhbEDvTDRmZ8E9dvxq9Tnza/t056bkD02TR3WP3YNCdaIYGf5e6Nx7MHuwJ6kgJfnpzMQTkTDcThYER3sqJ3iLUYaHOWTp56CcoEkeDBWgPe5JB1XCLMBNUeUJyiJsyOQyqfBvC/2XQzXqfmFkFRXeTwKDiv6TqR6y//79Op2Uxslxb+ZtOfuc9EkSnxF7sPOt7JR4wBCdhL2k2NKmT/3UyYOLpvS6qz68aNV/wE=',
  'RelayState' => 'phpgrc-health-lcnn4qpviyza',
  'SigAlg' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
  'Signature' => 'gIzkFK4XiEAzJStEWpw5OrN5VvRikoGct+oNFxzhihlpvoMClV5FBSkX/R0I2pD3Wuqezcp/+czbwptKjsGJ4Tr9CjJEKH3NLc8KI5WjkVxhu6QnDqHsUhvu2pyw9S8GoxtLUcTe9IXZAzkJ9KumFm6gOP011jRFAm5zJzVPVzUY1j7qBF3BKx7ZuJuiS+KGjLGanvYVtvnMeYFm+cpCkBkx6B58+X1DKEcGxUrwAj6eHPoI4JYYtiicyUZAMjyAEdiw52LwOMx6GcD/SotALP/u9pJKPu38JIV2HhOusCpoJqykdPHWURg/38pMTbA3Lr3qS2zo08lf/zEw4aCPxzN4mRTfsEjof1jPwD4Dncjt+BA08ORHvGmgNu02nCNOpDO5xPgGJw5t8krnA9U0rvqBfb66rcfzkQkQyq97UMA6b9S2kiiK8pXbTd/AE8hGny1/THeWWPaOAreOXWkohuiNFafCTJdIrniea59TeOH2ZlPx5QbIXFHR84DjHd1ha8NmhSMwS1MYDBllnZXv6DEnYPfCF0Nl0a1lYC55r2if+HV1W8Ix3VxfthWeGumjtbhBeHTx/sr+rRmYq7IASVSgSHAp2+j0knaZdIcwiyRXhTEWBUsqESvPXmy2ApvW1qF7H9zWCW8mscjEzrMGlee/3hWTusmJNfNdPqr1Hd4=',
);
$message = 'SAMLRequest=' . rawurlencode($params['SAMLRequest']) . '&RelayState=' . rawurlencode($params['RelayState']) . '&SigAlg=' . rawurlencode($params['SigAlg']);
$sig = base64_decode($params['Signature'], true);
$cert = file_get_contents('/tmp/sp.crt');
$pub = openssl_pkey_get_public($cert);
var_dump(openssl_verify($message, $sig, $pub, OPENSSL_ALGO_SHA256));
while ($err = openssl_error_string()) {
    echo "OPENSSL: $err\n";
}
