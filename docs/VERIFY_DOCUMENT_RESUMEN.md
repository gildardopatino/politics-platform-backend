# Verificación de documento — obsoleto

Este archivo era el informe de entrega de la primera versión del endpoint. Su
contenido ya no describe el sistema: daba por bueno que la ruta fuera **pública
y sin autenticación**, que es justamente la fuga de PII cross-tenant que cerró
la **Spec 0026**. Los ejemplos de `curl` que traía responden hoy `401`.

El contrato vigente —las dos rutas, qué campos devuelve cada una y por qué— está
en **[`VERIFY_DOCUMENT_API.md`](VERIFY_DOCUMENT_API.md)**.
