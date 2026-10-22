import { SwaggerUIBundle } from 'swagger-ui-dist';
import 'swagger-ui-dist/swagger-ui.css';
import '../css/backoffice-swagger.css';

function initSwagger() {
  const element = document.getElementById('mskba-swagger-ui');
  if (!element || element.dataset.mskbaMounted === '1') return;

  const specUrl = element.dataset.specUrl;
  if (!specUrl || new URL(specUrl, location.href).origin !== location.origin) return;

  element.dataset.mskbaMounted = '1';

  // Never validate schemas against an external service. Authentication stays
  // entirely in browser memory; Swagger UI does not persist credentials.
  SwaggerUIBundle({
    domNode: element,
    url: specUrl,
    deepLinking: true,
    presets: [SwaggerUIBundle.presets.apis],
    layout: 'BaseLayout',
    validatorUrl: null,
    persistAuthorization: false,
    filter: true,
    docExpansion: 'list',
    defaultModelsExpandDepth: 1,
    displayRequestDuration: true,
    tryItOutEnabled: false,
    supportedSubmitMethods: ['get', 'post'],
    queryConfigEnabled: false,
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initSwagger, { once: true });
} else {
  initSwagger();
}
document.addEventListener('livewire:navigated', initSwagger);
document.addEventListener('livewire:navigating', () => {
  // Recreate on next navigation; do not preserve API credentials.
  const element = document.getElementById('mskba-swagger-ui');
  if (element) {
    element.replaceChildren();
    delete element.dataset.mskbaMounted;
  }
});
