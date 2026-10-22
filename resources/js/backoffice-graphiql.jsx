import React from 'react';
import { createRoot } from 'react-dom/client';
import { GraphiQL } from 'graphiql';
import { createGraphiQLFetcher } from '@graphiql/toolkit';
import 'graphiql/style.css';
import '../css/backoffice-graphiql.css';

const defaultQuery = `# Публичные площадки MSKBA. Выберите поля и нажмите Run.
query Venues {
  venues(first: 2) {
    edges {
      node {
        id
        name
        type
        address
      }
    }
    pageInfo {
      hasNextPage
      endCursor
    }
  }
}`;

function initializeGraphiQL() {
  const element = document.getElementById('mskba-graphiql-root');
  if (!element || element.dataset.mounted === '1') return;

  const endpoint = element.dataset.endpoint;
  if (!endpoint || new URL(endpoint, window.location.href).origin !== window.location.origin) return;

  const tokenInput = document.getElementById('mskba-graphiql-token');
  const clearButton = document.getElementById('mskba-graphiql-clear-token');
  let token = '';

  const onChange = () => {
    token = tokenInput?.value?.trim() ?? '';
  };

  tokenInput?.addEventListener('input', onChange);
  clearButton?.addEventListener('click', () => {
    token = '';
    if (tokenInput) tokenInput.value = '';
  });

  const fetcher = createGraphiQLFetcher({
    url: endpoint,
    fetch: (resource, options = {}) => {
      const headers = new Headers(options.headers ?? {});
      // Each invocation reads the token from in-memory state; do not persist it.
      if (token) {
        headers.set('Authorization', `Bearer ${token}`);
      } else {
        headers.delete('Authorization');
      }
      return window.fetch(resource, {
        ...options,
        headers,
        credentials: 'omit',
      });
    },
  });

  element.dataset.mounted = '1';
  const root = createRoot(element);
  root.render(React.createElement(GraphiQL, {
    fetcher,
    defaultQuery,
  }));

  // Livewire can replace the Filament page during SPA navigation.
  document.addEventListener('livewire:navigating', () => {
    root.unmount();
    token = '';
    if (tokenInput) tokenInput.value = '';
    delete element.dataset.mounted;
  }, { once: true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeGraphiQL, { once: true });
} else {
  initializeGraphiQL();
}
document.addEventListener('livewire:navigated', initializeGraphiQL);
