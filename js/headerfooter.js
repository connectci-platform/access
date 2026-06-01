/**
 * @file
 * Header and footer JS.
 */

import {
  footer,
  footerMenus,
  header,
  qaBot,
  siteMenus,
  universalMenus,
} from "https://unpkg.com/@access-ci/ui@0.20.7/dist/access-ci-ui.js";

(function (Drupal, drupalSettings) {

  'use strict';

  let currentUri = drupalSettings.access.current_uri;

  /**
   * Attaches the JS test behavior to weight div.
   */
  Drupal.behaviors.accessMenuData = {
    attach: function (context, settings) {
      // Only run once on initial page load
      if (context !== document) {
        return;
      }

      let currentMenu = drupalSettings.access.current_menu;
      try {
        currentMenu = JSON.parse(currentMenu);
      } catch (e) {
        console.error("Failed to parse currentMenu:", e);
      }
      setMenu(currentMenu, currentUri);
    }
  };
})(Drupal, drupalSettings);

async function getLogoutToken() {
  try {
    const response = await fetch('/session/logout/token')

    if (!response.ok) {
      throw new Error(`Error: ${response.status}`)
    }

    const token = await response.text()
    return token
  } catch (error) {
    console.error('Error fetching logout token:', error)
    return null
  }
}

async function setMenu(menu, currentUri) {
  let mainMenu = menu;

  const siteItems = mainMenu;
  const isLoggedIn = document.body.classList.contains("user-logged-in");
  let logoutUrl = "/user/logout"

  if (isLoggedIn) {
    await getLogoutToken().then(token => {
      logoutUrl += `?token=${token}`
    });
  }

  const targets = {
    'universal-menus': document.getElementById("universal-menus"),
    'header': document.getElementById("header"),
    'site-menus': document.getElementById("site-menus"),
    'footer-menus': document.getElementById("footer-menus"),
    'footer': document.getElementById("footer"),
  };

  const missing = Object.entries(targets).filter(([, el]) => !el).map(([id]) => id);
  if (missing.length) {
    console.warn('access-ci-ui: missing target elements:', missing.join(', '));
    return;
  }

  universalMenus({
    isLoggedIn: isLoggedIn,
    loginUrl: "/login?redirect=" + currentUri,
    logoutUrl,
    siteName: "Support",
    target: targets['universal-menus'],
  });

  header({
    siteName: "Support",
    target: targets['header'],
  });

  siteMenus({
    items: siteItems,
    siteName: "Support",
    target: targets['site-menus'],
  });

  footerMenus({
    items: siteItems,
    siteName: "Support",
    target: targets['footer-menus'],
  });

  footer({
    target: targets['footer'],
  });

  const { email = '', name = '', accessId = '' } = drupalSettings.access.user || {};
  const apiKey = "4nn5l4T4TnkMdzsK0AwAtnGRcheBDnjawuAT42LaOLc";

  // GTM dataLayer event handler for QA bot analytics
  const chatbotEnv = drupalSettings.access.environment || 'unknown';
  const onAnalyticsEvent = (event) => {
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ event: event.type, ...event, chatbot_env: chatbotEnv });
  };

  // Default to the agent at qa.access-ci.org. drupalSettings.access.qaEndpoint
  // can override (used by staging/dev to point at qa-bot-proxy or a local agent).
  const qaEndpoint = drupalSettings.access.qaEndpoint || "https://qa.access-ci.org/api/v1/query";

  // Initialize floating qa-bot if target exists (using npm version)
  const floatingTarget = document.getElementById("qa-bot");
  if (floatingTarget && !floatingTarget.hasAttribute('data-initialized')) {
    qaBot({
      target: floatingTarget,
      apiKey: apiKey,
      isLoggedIn: isLoggedIn,
      userEmail: email,
      userName: name,
      accessId: accessId,
      loginUrl: "/login?redirect=" + currentUri,
      onAnalyticsEvent: onAnalyticsEvent,
      qaEndpoint: qaEndpoint,
    });
    floatingTarget.setAttribute('data-initialized', 'true');
  }

  // Initialize embedded qa-bot if target exists (using npm version)
  const embeddedTarget = document.querySelector(".embedded-qa-bot");
  if (embeddedTarget && !embeddedTarget.hasAttribute('data-initialized')) {
    qaBot({
      target: embeddedTarget,
      embedded: true,
      apiKey: apiKey,
      isLoggedIn: isLoggedIn,
      userEmail: email,
      userName: name,
      accessId: accessId,
      resourceContext: embeddedTarget.dataset.resourceContext || undefined,
      loginUrl: "/login?redirect=" + currentUri,
      onAnalyticsEvent: onAnalyticsEvent,
      qaEndpoint: qaEndpoint,
    });
    embeddedTarget.setAttribute('data-initialized', 'true');
  }

};
