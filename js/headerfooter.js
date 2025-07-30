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
} from "/libraries/access-ci-ui/access-ci-ui.js";

(function (Drupal, drupalSettings) {

  'use strict';

  let currentUri = drupalSettings.access.current_uri;

  /**
   * Attaches the JS test behavior to weight div.
   */
  Drupal.behaviors.accessMenuData = {
    attach: function (context, settings) {
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

  qaBot({
    target: document.getElementById("qa-bot"),
    apiKey: apiKey,
    isLoggedIn: isLoggedIn,
    userEmail: email,
    userName: name,
    accessId: accessId,
    loginUrl: "/login?redirect=" + currentUri,
  });

  qaBot({
    target: document.querySelector(".embedded-qa-bot"),
    embedded: true,
    apiKey: apiKey,
    isLoggedIn: isLoggedIn,
    userEmail: email,
    userName: name,
    accessId: accessId,
    loginUrl: "/login?redirect=" + currentUri,
  });
 
};
