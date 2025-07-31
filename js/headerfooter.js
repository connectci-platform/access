/**
 * @file
 * Header and footer JS.
 */

import {
  footer,
  footerMenus,
  header,
  siteMenus,
  universalMenus,
} from "https://unpkg.com/@access-ci/ui@0.9.0/dist/access-ci-ui.js";

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

  universalMenus({
    isLoggedIn: isLoggedIn,
    loginUrl: "/login?redirect=" + currentUri,
    logoutUrl,
    siteName: "Support",
    target: document.getElementById("universal-menus"),
  });

  header({
    siteName: "Support",
    target: document.getElementById("header"),
  });

  siteMenus({
    items: siteItems,
    siteName: "Support",
    target: document.getElementById("site-menus"),
  });

  footerMenus({
    items: siteItems,
    siteName: "Support",
    target: document.getElementById("footer-menus"),
  });

  footer({
    target: document.getElementById("footer")
  });

  const { email = '', name = '', accessId = '' } = drupalSettings.access.user || {};
  const apiKey = "4nn5l4T4TnkMdzsK0AwAtnGRcheBDnjawuAT42LaOLc";

  console.log('QA Bot initialization:', {
    userFromDrupal: drupalSettings.access.user,
    extractedValues: { email, name, accessId },
    isLoggedIn: isLoggedIn,
    currentUri: currentUri,
  });

  // Initialize floating qa-bot if target exists (using npm version)
  const floatingTarget = document.getElementById("qa-bot");
  if (floatingTarget && !floatingTarget.hasAttribute('data-initialized') && window.qaBot) {
    window.qaBot({
      target: floatingTarget,
      apiKey: apiKey,
      isLoggedIn: isLoggedIn,
      userEmail: email,
      userName: name,
      accessId: accessId,
      loginUrl: "/login?redirect=" + currentUri,
    });
    floatingTarget.setAttribute('data-initialized', 'true');
  }

  // Initialize embedded qa-bot if target exists (using npm version)
  const embeddedTarget = document.querySelector(".embedded-qa-bot");
  if (embeddedTarget && !embeddedTarget.hasAttribute('data-initialized') && window.qaBot) {
    window.qaBot({
      target: embeddedTarget,
      embedded: true,
      apiKey: apiKey,
      isLoggedIn: isLoggedIn,
      userEmail: email,
      userName: name,
      accessId: accessId,
      loginUrl: "/login?redirect=" + currentUri,
    });
    embeddedTarget.setAttribute('data-initialized', 'true');
  }

};
