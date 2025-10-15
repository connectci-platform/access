<?php

namespace Drupal\access_affinitygroup\Plugin;

use Drupal\access_affinitygroup\Plugin\ConstantContactApi;

/**
 * @file
 * Returns the HTML to send to Constant Contact.
 * Email templates for affinity groups in the Community category or the ACCESS_RP
 * category. Used in emails to Affinity groups sent by coordinators, broadcast of
 * events and announcements, and for the Access Digest.
 *
 * Note: this code refers to "news" which has since been renamed to "announcements" on the website.
 * The website now has "ACCESS News", which are articles suggested by community and written by communications
 * team, and "announcenments", which anyone can submit for approval. We deal with the latter here.
 */
/**
 *
 */
class EmailWrapper {

  private $bgcolor = '#ED3F27';
  private $titleBgColor = '#134686';
  private $titleColor = '#134686';
  private $linkBackground = '#C91235';
  private $linkColor = '#ffffff';
  private $linkHoverBackground = '#545860';
  private $linkHoverBorderColor = '#545860';

  /**
   * Template colors.
   */
  public function __construct() {
    $cca = new ConstantContactApi();
    $env = $cca->getEnvironment();

    if ($env == 'support') {
      $this->bgcolor = '#138597';
      $this->titleBgColor = '#1a5b6e';
      $this->titleColor = 'rgb(26, 91, 110)';
      $this->linkBackground = '#ffc42d';
      $this->linkColor = '#000000';
      $this->linkHoverBackground = '#ffffff';
      $this->linkHoverBorderColor = '#000000';
    }
    elseif ($env == 'openondemand') {
      $this->bgcolor = '#DDDEDF';
      $this->titleBgColor = '#545860';
      $this->titleColor = '#545860';
      $this->linkBackground = '#C91235';
      $this->linkColor = '#ffffff';
      $this->linkHoverBackground = '#545860';
      $this->linkHoverBorderColor = '#545860';
    }
  }

  /**
   * NewsBody: the next text
   * newsTitle: headline
   * pubDate: date to display
   * agNames: array of affinity group names for top line
   * newsUrl: for button link to news item. If null, no button.
   * logoUrl: url for logo image, or null for none.
   */
  public function ccCommunityNewsHTML($newsBody, $newsTitle, $pubDate, $agNames, $newsUrl, $logoUrl) {
    // Build list of one or more affinity group names separated by 'or'.
    $agText = '';
    $or = '';
    foreach ($agNames as $agName) {
      $agText = $agText . $or . $agName;
      $or = ' or ';
    }

    $agText = 'You are receiving this email through the ' . $agText . ' Affinity Group.';

    $pubDateDisplay = NULL;
    if ($pubDate) {
      $pubDateDisplay = <<<PUBDATE
        <table width="100%" border="0"
            cellpadding="0" cellspacing="0"
            style="table-layout:fixed;"
            class="text text--padding-vertical">
            <tbody>
                <tr>
                    <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;color:#3E3E3E;font-size:14px;line-height:1.2;display:block;word-wrap:break-word;padding:0px 40px;"
                        align="left"
                        valign="top"
                        class="text_content-cell content-padding-horizontal">
                        <p style="margin:0;">
                            $pubDate
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
    PUBDATE;
    }

    $logoDisplay = '';
    if ($logoUrl != NULL) {
      $logoDisplay = <<<LOGOHTML
        <table width="100%" border="0" cellpadding="0" cellspacing="0" class="image image--padding-vertical image--mobile-scale image--mobile-center">
            <tbody>
                <tr>
                    <td align="left" valign="top" style="padding:10px 40px;" class="image_container content-padding-horizontal">
                        <img width="240"
                            src="$logoUrl"
                            alt="Affinity group logo"
                            style="display:block;height:auto;max-width:100%;"
                            class="image_content">
                    </td>
                </tr>
            </tbody>
        </table>
    LOGOHTML;
    }

    $websiteButtonDisplay = '';
    if ($newsUrl != NULL) {
      $websiteButtonDisplay = <<<WEBSITEBUTTONHTML
        <table width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed;">
            <tbody>
                <tr>
                    <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;padding:20px 40px 15px 40px;" align="left" valign="top">
                        <a href="$newsUrl" rel="nofollow noopener noreferrer" class="view-on-website-btn">VIEW ON WEBSITE</a>
                    </td>
                </tr>
            </tbody>
        </table>
    WEBSITEBUTTONHTML;
    }

    // HTML with values for newsBody, newsTitle, pubdate and agText inserted.
    $emailText = <<<EMAILTEXT
  <html>
  <body>[[trackingImage]]
      <div id="">
          <style type="text/css">

            a.view-on-website-btn {
              font-family:Roboto,sans-serif;
              font-size: 16px!important;
              font-weight: bold!important;
              text-decoration: none!important;
              background-color:$this->linkBackground!important;
              border-color:$this->linkBackground!important;
              width: fit-content!important;
              color: $this->linkColor!important;
              padding: 10px 20px;
              border: 4px solid;
              margin-bottom:15px;
            }

            a.view-on-website-btn:hover {
              background-color: $this->linkHoverBackground!important;
              border-color: $this->linkHoverBorderColor!important;
              border: 4px solid;
            }

            p {
              margin-bottom: 10px!important;
            }

              @media only screen and (max-width:1200px) {
                  # .footer-main-width {
                      width: 100% !important;
                  }

                  # .footer-mobile-hidden {
                      display: none !important;
                  }

                  # .footer-mobile-hidden {
                      display: none !important;
                  }

                  # .footer-column {
                      display: block !important;
                  }

                  # .footer-mobile-stack {
                      display: block !important;
                  }

                  # .footer-mobile-stack-padding {
                      padding-top: 3px;
                  }
              }

              .field--name-title, .field--name-recur-type, .field--name-event-instances .field__label, .field--name-field-affinity-group-node, .field--name-field-tags {
                display: none;
              }
              .field { margin: 15px 0; }

              img {}

              .layout {
                  min-width: 100%;
              }

              table {
                  table-layout: fixed;
              }

              .shell_outer-row {
                  table-layout: auto;
              }

                u+.body .shell_outer-row {
                  width: 700px;
              }

                .text .text_content-cell p {
                  margin: 0;
                  padding: 0;
                  margin-bottom: 0;
              }

               .text .text_content-cell ul,
               .text .text_content-cell ol {
                  padding: 0;
                  margin: 0 0 0 40px;
              }

               .text .text_content-cell li {
                  padding: 0;
                  margin: 0;
              }

                a {
                  text-decoration: underline;
              }

              a .filtered99999 {
                  text-decoration: underline !important;
                  font-size: inherit !important;
                  font-family: inherit !important;
                  font-weight: inherit !important;
                  line-height: inherit !important;
                  color: inherit !important;
              }

              .text .text_content-cell {}
          </style>
          <style>
              @media only screen and (max-width:1200px) {
                   .button_content-cell {
                      padding-top: 10px !important;
                      padding-right: 20px !important;
                      padding-bottom: 10px !important;
                      padding-left: 20px !important;
                  }

                   .button_border-row .button_content-cell {
                      padding-top: 10px !important;
                      padding-right: 20px !important;
                      padding-bottom: 10px !important;
                      padding-left: 20px !important;
                  }

                   .column .content-padding-horizontal {
                      padding-left: 20px !important;
                      padding-right: 20px !important;
                  }

                   .layout .column .content-padding-horizontal .content-padding-horizontal {
                      padding-left: 0px !important;
                      padding-right: 0px !important;
                  }

                   .layout .column .content-padding-horizontal .block-wrapper_border-row .content-padding-horizontal {
                      padding-left: 20px !important;
                      padding-right: 20px !important;
                  }

                   .dataTable {
                      overflow: auto !important;
                  }

                   .dataTable .dataTable_content {
                      width: auto !important;
                  }

                   .image--mobile-scale .image_container img {
                      width: auto !important;
                  }

                   .image--mobile-center .image_container img {
                      margin-left: auto !important;
                      margin-right: auto !important;
                  }

                   .layout-margin .layout-margin_cell {
                      padding: 0px 20px !important;
                  }

                   .layout-margin--uniform .layout-margin_cell {
                      padding: 20px 20px !important;
                  }

                   .scale {
                      width: 100% !important;
                      height: auto !important;
                  }

                   .stack {
                      display: block !important;
                  }

                   .hide {
                      display: none !important;
                  }

                   u+.body .shell_outer-row {
                      width: 100% !important;
                  }

                   .socialFollow_container {
                      text-align: center !important;
                  }

                   .text .text_content-cell {
                      font-size: 16px !important;
                  }

                   .text .text_content-cell h1 {
                      font-size: 24px !important;
                  }

                   .text .text_content-cell h2 {
                      font-size: 20px !important;
                  }

                   .text .text_content-cell h3 {
                      font-size: 20px !important;
                  }

                   .text--sectionHeading .text_content-cell {
                      font-size: 24px !important;
                  }

                   .text--heading .text_content-cell {
                      font-size: 24px !important;
                  }

                   .text--dataTable .text_content-cell .dataTable .dataTable_content-cell {
                      font-size: 14px !important;
                  }

                   .text--dataTable .text_content-cell .dataTable th.dataTable_content-cell {}
              }
          </style>
          <div>

              <div lang="en-US" style="background-color:$this->bgcolor;" class="shell">
                  <table width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color:$this->bgcolor;"
                      bgcolor="$this->bgcolor" class="shell_panel-row">
                      <tbody>
                          <tr>
                              <td style="" align="center" valign="top" class="shell_panel-cell">
                                  <table style="width:90%; max-width:700px;" align="center" border="0" cellpadding="0" cellspacing="0"
                                      class="shell_width-row scale">
                                      <tbody>
                                          <tr>
                                              <td style="padding:15px 10px;" align="center" valign="top"
                                                  class="shell_width-cell">
                                                  <table width="100%" align="center" border="0" cellpadding="0"
                                                      cellspacing="0" class="shell_content-row">
                                                      <tbody>
                                                          <tr>
                                                              <td style="background-color:#ffffff;padding:0;"
                                                                  align="center" valign="top" bgcolor="#ffffff"
                                                                  class="shell_content-cell">
                                                                  <table
                                                                      style="background-color:$this->titleBgColor;table-layout:fixed;"
                                                                      width="100%" border="0" cellpadding="0"
                                                                      cellspacing="0" bgcolor="$this->titleBgColor"
                                                                      class="layout layout--1-column">
                                                                      <tbody>
                                                                          <tr>
                                                                              <td style="width:100%;" align="center"
                                                                                  valign="top"
                                                                                  class="column column--1 scale stack">

                                                                                  <table width="100%" border="0"
                                                                                      cellpadding="0" cellspacing="0"
                                                                                      style="table-layout:fixed;"
                                                                                      class="text text--padding-vertical">
                                                                                      <tbody>
                                                                                          <tr>
                                                                                              <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;color:#3E3E3E;font-size:14px;line-height:1;display:block;word-wrap:break-word;padding:12px 40px;"
                                                                                                  align="left"
                                                                                                  valign="top"
                                                                                                  class="text_content-cell content-padding-horizontal">
                                                                                                  <p style="margin:0 !important;">
                                                                                                      <span style="color:rgb(255, 255, 255);">
                                                                                                        $agText
                                                                                                      </span>
                                                                                                  </p>
                                                                                              </td>
                                                                                          </tr>
                                                                                      </tbody>
                                                                                  </table>
                                                                              </td>
                                                                          </tr>
                                                                      </tbody>
                                                                  </table>
                                                                  <table style="table-layout:fixed;" width="100%"
                                                                      border="0" cellpadding="0" cellspacing="0"
                                                                      class="layout layout--1-column">
                                                                      <tbody>
                                                                          <tr>
                                                                              <td style="width:100%;" align="center"
                                                                                  valign="top"
                                                                                  class="column column--1 scale stack">
                                                                                  $logoDisplay
                                                                                  <div style="line-height:20px;min-height:20px;"
                                                                                      class="spacer"> </div>
                                                                                  <table width="100%" border="0"
                                                                                      cellpadding="0" cellspacing="0"
                                                                                      style="table-layout:fixed;"
                                                                                      class="text text--padding-vertical">
                                                                                      <tbody>
                                                                                          <tr>
                                                                                              <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;color:#3E3E3E;font-size:14px;line-height:1.2;display:block;word-wrap:break-word;padding:10px 40px 0px 40px;"
                                                                                                  align="left"
                                                                                                  valign="top"
                                                                                                  class="text_content-cell content-padding-horizontal">
                                                                                                  <p style="margin:0;">
                                                                                                      <span style="font-size:16px;font-weight:bold;color:$this->titleColor;">
                                                                                                      $newsTitle
                                                                                                      </span>
                                                                                                  </p>
                                                                                              </td>
                                                                                          </tr>
                                                                                      </tbody>
                                                                                  </table>
                                                                                  $pubDateDisplay
                                                                                  <table width="100%" border="0"
                                                                                      cellpadding="0" cellspacing="0"
                                                                                      style="table-layout:fixed;"
                                                                                      class="text text--padding-vertical">
                                                                                      <tbody>
                                                                                          <tr>
                                                                                              <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;color:#3E3E3E;font-size:14px;line-height:1.2;display:block;word-wrap:break-word;padding:0px 40px;"
                                                                                                  align="left"
                                                                                                  valign="top"
                                                                                                  class="text_content-cell content-padding-horizontal">
                                                                                                  $newsBody
                                                                                              </td>
                                                                                          </tr>
                                                                                      </tbody>
                                                                                  </table>
                                                                                  $websiteButtonDisplay
                                                                                  <div style="min-height:30px;line-height:30px;"
                                                                                      class="spacer"> </div>
                                                                              </td>
                                                                          </tr>
                                                                      </tbody>
                                                                  </table>
                                                              </td>
                                                          </tr>
                                                      </tbody>
                                                  </table>
                                              </td>
                                          </tr>
                                      </tbody>
                                  </table>
                              </td>
                          </tr>
                      </tbody>
                  </table>
              </div>
          </div>
      </div>
  </body>
  </html>
EMAILTEXT;
    // note: EMAILTEXT must be to the left column-wise of the last tag (php)
    return $emailText;
  }

  /**
   * Used in digest (announcements/events rollup).
   */
  public function mainHeadHTML($titleText) {
    $mainHead = <<<MAINHEADHTML
    <table class="layout layout-feature layout-1-column" style="table-layout:fixed;
           background-color=#ffffff;" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#ffffff">
      <tr>
        <td class="column column--1 scale stack" style="width:100%;" align="center" valign="top">
          <table class="text text--feature text--padding-vertical" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout: fixed;">
            <tr>
              <td class="text_content-cell content-padding-horizontal"
                        style="text-align: center; font-family: Roboto,sans-serif; color:#4d4d4d; font-size:14px; line-height:1.2;
                        display:block; word-wrap: break-word; padding: 10px 40px;" align="center" valign="top">
                <h1 style="text-align:left; font-family:Roboto,sans-serif; color: #3E3E3E; font-size: 24px; font-weight:bold; margin:0;" align="left">
                  <span style="font-family:Roboto,sans-serif; color:rgb(0, 92, 111); font-weight:bold;">$titleText</span>
                </h1>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
MAINHEADHTML;
    // note: SECTIONHEADHTML must be to the left column-wise of the last tag (php)
    return $mainHead;
  }

  /**
   * Used in digest (announcements/events rollup).
   */
  public function sectionHeadHTML($titleText) {
    $sectionHead = <<<SECTIONHEADHTML
    <table class="layout layout-feature layout-1-column" style="table-layout:fixed;
           background-color=#ffffff;" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#ffffff">
      <tr>
        <td class="column column--1 scale stack" style="width:100%;" align="center" valign="top">
          <table class="text text--feature text--padding-vertical" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout: fixed;">
            <tr>
              <td class="text_content-cell content-padding-horizontal"
                        style="text-align: center; font-family: Roboto,sans-serif; color:#4d4d4d; font-size:14px; line-height:1.2;
                        display:block; word-wrap: break-word; padding: 10px 40px;" align="center" valign="top">
                <h2 style="text-align:left; font-family:Roboto,sans-serif; background-color: rgb(16, 113, 128); font-size: 18px; font-weight:bold; padding: 8px 10px; margin:0;" align="left">
                  <span style="font-family:Roboto,sans-serif; color: #fff; font-weight:bold;">$titleText</span>
                </h2>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
SECTIONHEADHTML;
    // note: SECTIONHEADHTML must be to the left column-wise of the last tag (php)
    return $sectionHead;
  }

  /**
   * Used in digest (announcements/events rollup).
   */
  public function sectionSubHeadHTML($titleText) {
    $sectionSubHead = <<<SECTIONSUBHEADHTML
    <table class="layout layout-feature layout-1-column" style="table-layout:fixed;
           background-color=#ffffff;" width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#ffffff">
      <tr>
        <td class="column column--1 scale stack" style="width:100%;" align="center" valign="top">
          <table class="text text--feature text--padding-vertical" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout: fixed;">
            <tr>
              <td class="text_content-cell content-padding-horizontal"
                        style="text-align: center; font-family: Roboto,sans-serif; color:#4d4d4d; font-size:12px; line-height:1.2;
                        display:block; word-wrap: break-word; padding: 10px 40px;" align="center" valign="top">
                <h3 style="text-align:left; font-family:Roboto,sans-serif; color: #3E3E3E; background-color: rgb(236 249 248); font-size: 18px; font-weight:bold; padding: 8px 10px; margin:0;" align="left">
                  <span style="font-family:Roboto,sans-serif; color:$this->titleBgColor;">$titleText</span>
                </h3>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
SECTIONSUBHEADHTML;
    // note: SECTIONHEADHTML must be to the left column-wise of the last tag (php)
    return $sectionSubHead;
  }

  /**
   * Use in weekly news rollup.
   */
  public function newsItemHTML($title, $pubDate, $body, $articleUrl) {
    $main = "<div  class=\"digest-news-item\">
      <div class=\"digest-news-text\">
      $body
      </div>
      </div>";
    return $this->itemHTML($title, $pubDate, $main, $articleUrl, "Read more");
  }

  /**
   * Use in weekly news rollup.
   */
  public function eventItemHTML($title, $eventDate, $description, $articleUrl) {
    $main = "<div>
        <div class=\"digest-news-text\">$description</div>
      </div>";
    return $this->itemHTML($title, $eventDate, $main, $articleUrl, "Read more");
  }

  /**
   * Used in announcements/events digest - each announcement or event item
   * with a link at the bottom to the event.
   */
  private function itemHTML($titleText, $eventDate, $main, $itemUrl, $itemLinkText) {
    $title = $this->titleHTML($titleText);

    $articleLink = '';

    if ($itemUrl != NULL) {
      $articleLink = <<<ARTICLELINK
              <table border="0" cellpadding="0" cellspacing="0" bgcolor="#48c0b9"
                    style="table-layout:fixed;width:inherit;border-radius:2px;border-spacing:0px;background-color:rgb(72,192,185);border:none;margin-bottom:10px;">
                <tbody>
                  <tr>
                    <td align="center" style="padding:4px 6px;">
                      <a href="$itemUrl"
                       style="text-decoration:none;color:rgb(255,255,255);font-family:Roboto,sans-serif;font-size:12px;font-weight:bold"
                       target="_blank"
                       rel="nofollow noopener noreferrer">
                       $itemLinkText
                      </a>
                    </td>
                  </tr>
                </tbody>
              </table>
      ARTICLELINK;
    }

    $article = <<<ARTICLEHTML
  <table class="layout layout--1-column" style="table-layout: fixed;" width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td class="column column--1 scale stack" style="width:=65%;" align="center" valign="top">
        <table class="text text--article text--padding-vertical" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed;">
          <tr>
            <td class="text_content-cell content-padding-horizontal" style="text-align: left; font-family:Roboto,sans-serif;
            color: #4d4d4d; font-size: 14px; line-height: 1.2; display: block; word-wrap: break-word; padding: 10px 40px;" align="left" valign="top">
            <div class="digest-news-text" style="margin-bottom: 10px; font-weight: bold;">$eventDate</div>
            $title

              <div><span style="font-size: 14px;">$main</span></div>

              $articleLink
          </td>
        </tr>
      </table>
    </td>
    </tr>
  </table>
ARTICLEHTML;
    // Previous line text marker must positioned to the left of end html.
    return $article;
  }

  /**
   * A line between articles.
   */
  public function dividerHTML() {
    $divider = <<<DIVIDERHTML
  <table class="layout layout--1-column" style="table-layout:fixed;"width="100%" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td class="column column--1 scale stack" style="width:100%;"align="center" valign="top">
        <table class="divider" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td class="divider_container content-padding-horizontal" style="padding: 10px 40px;" width="100%" align="center" valign="top">
              <table class="divider_content-row" style="height:1px; width:100%;" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td class="divider_content-cell"
                      style="background-color:#d6d6d6; height:1px; line-height:1px; padding-bottom:0px; border-bottom-width:0px;"
                      height="1" align="center" bgcolor="#d6d6d6">
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
DIVIDERHTML;
    // Note: the previous line text marker must be positioned to left of end of html.
    return $divider;
  }

  /**
   * Inner wrapper for the weekly news and events rollup.
   */
  public function ccNewsRollupHTML($news, $events) {
    $newsBody = '<div class="access-news-rollup-email">'
      . '<div class="access-news-rollup-news">' . $news . '</div>'
      . '<div class="access-news-rollup-events">' . $events . '</div>'
      . $this->sectionHeadHTML(t("Join Affinity Groups"))
      . $this->ccRollupBottomStatic1()
      . $this->sectionHeadHTML(t("Share with the ACCESS Community"))
      . $this->ccRollupBottomStatic2()
      . '</div>';

    return $this->ccNewsCommonHTML($newsBody, '');
  }

  /**
   * For a single news or event item, broadcast to one or more affinity groups
   * this is the Access template used for affinity groups that are NOT of the
   * "Community" category.
   */
  public function ccAccessNewsHTML($main, $title, $pubDate, $agNames, $newsUrl) {
    // Build list of one or more affinity group names separated by 'or'.
    $agText = '';
    $or = '';
    foreach ($agNames as $agName) {
      $agText = $agText . $or . $agName;
      $or = ' or ';
    }
    $agText = 'You are receiving this email through the ' . $agText . ' Affinity Group.';

    $titleDisplay = $this->titleHTML($title);

    // Line at the top that lists AG groups.
    $topExtra = <<<TOPEXTRA
    <table style="background-color:$this->titleBgColor;table-layout:fixed;" width="100%" border="0" cellpadding="0" cellspacing="0"  bgcolor="$this->titleBgColor">
      <tbody>
        <tr>
          <td style="width:100%;" align="center" valign="top ">
            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed; ">
              <tbody>
                <tr>
                  <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;color:#3E3E3E;font-size:14px;
                          line-height:1.2;display:block;word-wrap:break-word;"
                    align="left" valign="top">
                    <p style="margin:0;padding:5px;">
                      <span style="color:rgb(255, 255, 255);">$agText</span>
                    </p>
                  </td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
      </tbody>
    </table>
  TOPEXTRA;
    // Note: the previous line text marker must be positioned to left of end of html.
    $pubDateDisplay = '';
    if ($pubDate) {
      $pubDateDisplay = <<<PUBDATE
      <table width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed;">
        <tbody>
          <tr>
            <td style="text-align:left;font-family:Arial, Verdana, Helvetica, sans-serif;color:#3E3E3E;
                       font-size:14px;line-height:1.2;display:block;word-wrap:break-word;"
                       align="left" valign="top">
              <p style="margin:0;">
                $pubDate
              </p>
            </td>
          </tr>
        </tbody>
      </table>
    PUBDATE;
      // Note: the previous line text marker must be positioned to left of end of html.
    }

    $websiteButtonDisplay = '';
    if ($newsUrl != NULL) {
      $websiteButtonDisplay = <<<WEBSITEBUTTONHTML2
      <div style="text-align:left; padding-top:10px;padding-bottom:15px;">
        <a href="$newsUrl" rel="nofollow noopener noreferrer" class="view-on-website-btn">VIEW ON WEBSITE</a>
      </div>
    WEBSITEBUTTONHTML2;
    }

    $newsItem = <<<SINGLENEWS
      <table class="layout layout--1-column" style="table-layout: fixed;" width="100%" border="0" cellpadding="0" cellspacing="0">
        <tr>
          <td class="column column--1 scale stack" style="width:=65%;" align="center" valign="top">
            <table class="text text--article text--padding-vertical" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed;">
              <tr>
                <td class="text_content-cell content-padding-horizontal" style="text-align: left; font-family:Roboto,sans-serif; color: #4d4d4d;
                      font-size: 14px; line-height: 1.2; display: block; word-wrap: break-word; padding: 20px 40px 10px 40px;" align="left" valign="top">
                $titleDisplay
                $pubDateDisplay
                <span style="font-size: 14px;">$main</span>
                <p style="margin: 0;">
                <br>
                </p>
                $websiteButtonDisplay
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    SINGLENEWS;
    // Previous line text marker must positioned to the left of end html.
    return $this->ccNewsCommonHTML($newsItem, $topExtra);
  }

  /**
   * News or event title formatting.
   */
  private function titleHTML($titleText) {
    $t = <<<TITLE
    <h3 style="font-family:Roboto,sans-serif; color: #f07537; font-size: 18px; font-weight: bold; margin: 0; padding: 0px 0px 4px 0px">
      $titleText
    </h3>
  TITLE;
    return ($t);
  }

  /**
   * Returns complete url with host and full path
   * we assume all of our images are in the sites/default/files/inline_images dir.
   */
  private function imageUrl($imageFileName) {
    $uri = 'public://inline-images/' . $imageFileName;
    return (\Drupal::service('file_url_generator')->generateAbsoluteString($uri));
  }

  /**
   * Access Constant Contact Template wrapping common to broadcast news and events,
   * and also the weekly news+events rollup.
   */
  private function ccNewsCommonHTML($newsBody, $topExtra) {
    $imgLogo = $this->imageUrl('access_support_masthead.jpg');
    $fbIcon = $this->imageUrl('circleIconFacebook.png');
    $twIcon = $this->imageUrl('circleIconTwitter.png');
    $ytIcon = $this->imageUrl('circleIconYoutube.png');
    $nsfLogo = ACCESS_SUPPPORT_URL . '/themes/custom/accesstheme/assets/NSF_4-Color_bitmap_Logo_350x350.png';

    $emailText = <<<EMAILTEXT1
    <html lang="en-US">
    <head>
      <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
      <style type="text/css" data-premailer="ignore">

      /* for single event */
      .field--label-hidden.field__label{
        display: none;
      }

      div .field--name-title {
        display: none;
      }
      /* single news and events */

      a.view-on-website-btn {
        font-family:Roboto,sans-serif;
        font-size: 16px!important;
        font-weight: bold!important;
        text-decoration: none!important;
        background-color:$this->linkBackground!important;
        border-color:$this->linkBackground!important;
        width: fit-content!important;
        color: $this->linkColor!important;
        padding: 10px 20px!important;
        border: 4px solid;
      }

      a.view-on-website-btn:hover {
        background-color: $this->linkHoverBackground!important;
        border-color: $this->linkHoverBorderColor!important;
        border: 4px solid;
      }
       p {
        margin-bottom: 10px!important;
      }
      /* single event and news: spacing under fields such as location */
      .field__item:last-child {
        padding-bottom: 15px;
      }
      div .field--name-body {
        padding-bottom: 20px;
      }

      /* remove link styling from event instance date on series*/
      div .field--name-event-instances {
        padding-bottom: 15px;
      }
      .field--name-event-instances a {
        text-decoration: none!important;
        color: #000000!important;
        font-weight: bold!important;
      }
      div .field--name-body {
        padding-bottom: 20px;
      }

      @media only screen and (max-width:1200px) {
        .footer-main-width {
          width: 100% !important;
        }

        .footer-mobile-hidden {
          display: none !important;
        }

        .footer-mobile-hidden {
          display: none !important;
        }

        .footer-column {
          display: block !important;
        }

        .footer-mobile-stack {
          display: block !important;
        }

        .footer-mobile-stack-padding {
          padding-top: 3px;
        }
      }
      /* IE: correctly scale images with w/h attbs */
      img {
        -ms-interpolation-mode: bicubic;
      }

      .layout {
        min-width: 100%;
      }

      table {
        table-layout: fixed;
      }

      .shell_outer-row {
        table-layout: auto;
      }

      /* Gmail/Web viewport fix */
      u+.body .shell_outer-row {
        width: 700px;
      }

      @media screen {
        @font-face {
          font-family: 'Roboto';
          font-style: normal;
          font-weight: 400;
          src: local('Roboto'), local('Roboto-Regular'), url(https://fonts.gstatic.com/s/roboto/v18/KFOmCnqEu92Fr1Mu4mxKKTU1Kg.woff2) format('woff2');
          unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2212, U+2215;
        }
      }

      /* LIST AND p STYLE= OVERRIDES */
      .text .text_content-cell p {
        margin: 0;
        padding: 0;
        margin-bottom: 0;
      }

      .text .text_content-cell ul,
      .text .text_content-cell ol {
        padding: 0;
        margin: 0 0 0 40px;
      }

      .text .text_content-cell li {
        padding: 0;
        margin: 0;
      }

      /* Text Link Style Reset */
      a {
        text-decoration: underline;
        color:rgb(72,192,185);
        font-weight:bold;
      }
      /* needed because news body comes through wrapped in a p inconsistently */
      .digest-news-body p {
        padding: 0px;
      }
      .socialFollow a {
        text-decoration: none!important;
      }
      /* iOS: Autolink styles inherited*/
      a[x-apple-data-detectors] {
        text-decoration: underline !important;
        font-size: inherit !important;
        font-family: inherit !important;
        font-weight: inherit !important;
        line-height: inherit !important;
        color: inherit !important;
      }

      /* FF/Chrome: Smooth font rendering */
      .text .text_content-cell {
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
      }
    </style>
    <!--[if gte mso 9]>
    <style id="ol-styles">
    /* OUTLOOK-SPECIFIC STYLES */ li { text-indent: -1em; padding: 0; margin: 0; /* line-height: 1.2; Remove after testing */ }
      ul, ol { padding: 0; margin: 0 0 0 40px; }
    p { margin: 0; padding: 0; margin-bottom: 0; }=20
    </style>
    <![endif]-->
    <style>
      @media only screen and (max-width:1200px) {
        .button_content-cell {
          padding-top: 10px !important;
          padding-right: 20px !important;
          padding-bottom: 10px !important;
          padding-left: 20px !important;
        }

        .button_border-row .button_content-cell {
          padding-top: 10px !important;
          padding-right: 20px !important;
          padding-bottom: 10px !important;
          padding-left: 20px !important;
        }

        .column .content-padding-horizontal {
          padding-left: 20px !important;
          padding-right: 20px !important;
        }

        .layout .column .content-padding-horizontal .content-padding-horizontal {
          padding-left: 0px !important;
          padding-right: 0px !important;
        }

        .layout .column .content-padding-horizontal .block-wrapper_border-row .content-padding-horizontal {
          padding-left: 20px !important;
          padding-right: 20px !important;
        }

        .dataTable {
          overflow: auto !important;
        }

        .dataTable .dataTable_content {
          width: auto !important;
        }

        .image--mobile-scale .image_container img {
          width: auto !important;
        }

        .image--mobile-center .image_container img {
          margin-left: auto !important;
          margin-right: auto !important;
        }

        #nsf-image {
          width: 40%!important;
        }

        .layout-margin .layout-margin_cell {
          padding: 0px 20px !important;
        }

        .layout-margin--uniform .layout-margin_cell {
          padding: 20px 20px !important;
        }

        .scale {
          width: 100% !important;
        }

        .stack {
          display: block !important;
          box-sizing: border-box;
        }

        .hide {
          display: none !important;
        }

        u+.body .shell_outer-row {
          width: 100% !important;
        }

        .socialFollow_container {
          text-align: center !important;
        }

        .text .text_content-cell {
          font-size: 16px !important;
        }

        .text .text_content-cell h1 {
          font-size: 22px !important;
        }

        .text .text_content-cell h2 {
          font-size: 20px !important;
        }

        .text .text_content-cell h3 {
          font-size: 16px !important;
        }

        .text--sectionHeading .text_content-cell {
          font-size: 24px !important;
        }

        .text--heading .text_content-cell {
          font-size: 24px !important;
        }

        .text--dataTable .text_content-cell .dataTable .dataTable_content-cell {
          font-size: 14px !important;
        }

        .text--dataTable .text_content-cell .dataTable th.dataTable_content-cell {
          font-size: 18px !important;
        }
      }
      </style>
    </head>
    <body class="body template template--en-US" data-template-version="1.20.1" data-canonical-name="CPE-PT17831"  align="center"
        style="-ms-text-size-adjust:100%; -webkit-text-size-adjust: 100%; min-width: 00%; width: 100%; margin: 0px; padding: 0px;">
    [[trackingImage]]
    <div id="tracking-image" style="color: transparent; display: none; font-size: 1px; line-height: 1px; max-height: 0px; max-width: 0px;
             opacity: 0; overflow: hidden;"></div>
    <div class="shell" lang="en-US" style="background-color:$this->titleBgColor">
      <table class="shell_panel-row" width="100%" border="0" cellpadding="0" cellspacing="0" style="background-color:$this->titleBgColor;" bgcolor="$this->titleBgColor">
        <tr class="" >
          <td class="shell_panel-cell" style="" align="center" valign="top">
            <table class="shell_width-row scale" style="width:90%; max-width:700px;" align="center" border="0" cellpadding="0" cellspacing="0">
              <tr>
                <td class="shell_width-cell" style="padding: 15px 10px;" align="center" valign="top">
                  <table class="shell_content-row" width="100%" align="center" border="0" cellpadding="0" cellspacing="0">
                    <tr>
                      <td class="shell_content-cell" style="background-color: #FFFFFF; padding: 0; border: 0px solid #3e3e3e;"
                                align="center" valign="top" bgcolor="#FFFFFF">

                        $topExtra

                        <table class="layout layout--1-column" style="table-layout: fixed;" width="100%" border="0" cellpadding="0" cellspacing="0">
                          <tr>
                            <td class="column column--1 scale stack" style="width: 100%;"  align="center" valign="top">
                              <table class="image image--mobile-scale image--mobile-center"  width="100%" border="0" cellpadding="0" cellspacing="0">
                                <tr>
                                  <td class="image_container" align="center" valign="top">
                                    <img data-image-content class="image_content" width="680"
                                        src="$imgLogo"
                                        alt="ACCESS Support logo" style="display: block; height: auto; max-width:100%;">
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                        </table>

                        $newsBody

                        <table class="layout layout-1-column" style="table-layout: fixed;"  width="100%" border="0" cellpadding="0" cellspacing="0">
                          <tr>
                            <td class="column column-1 scale stack" style="width: 100%;"  align="center" valign="top">
                              <div class="spacer" style="line-height: 13px; height: 13px;"></div>
                            </td>
                          </tr>
                        </table>

                      <table width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed;min-width:100%">
                        <tbody>
                          <tr>
                            <td style="padding-bottom:4px;background-color:rgb(255,196,45);height:1px;line-height:1px;border-bottom-width:0px">
                            </td>
                          </tr>
                        </tbody>
                      </table>

                        <table class="layout-margin"  width="100%" border="0" cellpadding="0" cellspacing="0">
                          <tr>
                            <td class="layout-margin_cell" style="padding: 0px 40px;" align="center" valign="top">
                              <table class="layout layout--feature layout--3-column" style="table-layout: fixed; background-color: #ffffff;"
                                            width="100%" border="0" cellpadding="0" cellspacing="0" bgcolor="#ffffff">
                                <tr>

                                  <td class="column column--1 scale stack" style="width: 24%;"  align="center" valign="top">
                                    <table class="image image--padding-vertical image--mobile-scale image--mobile-center" width="100%"
                                                  border="0" cellpadding="0" cellspacing="0">
                                      <tr>
                                        <td class="image_container" align="left" valign="top" style="padding-top: 10px; padding-bottom: 10px;">
                                          <img id="nsf-image" width="57" src="$nsfLogo"
                                            alt="National Science Foundation logo" style="display:block;height:auto;max-width:100%">
                                        </td>
                                      </tr>
                                    </table>
                                  </td>

                                  <td class="column column--2 scale stack" style="width: 49%;"  align="center" valign="top">
                                    <table class="text text--feature text--padding-vertical"  width="100%" border="0" cellpadding="0" cellspacing="0"
                                           style="table-layout:fixed;">
                                      <tr>
                                        <td class="text_content-cell content-padding-horizontal"
                                            style="line-height:1; text-align: left; font-family:Roboto,sans-serif; color: #4d4d4d; font-size: 14px;
                                            display:block; word-wrap:break-word; padding: 10px;" align="left" valign="top">
                                          <p style="text-align: center; margin: 0;" align="center">
                                            <br>
                                          </p>
                                          <p style="text-align: center; margin:0;" align="center">
                                            <span style="font-size:12px; font-style: italic;">ACCESS is supported by the</span>
                                          </p>
                                          <p style="text-align:center; margin: 0;" align="center">
                                            <span style="font-size:12px; font-style: italic;">National Science Foundation. </span>
                                          </p>
                                        </td>
                                      </tr>
                                    </table>
                                  </td>

                                  <td class="column column--3 scale stack" style="width: 27%;"  align="center" valign="top">
                                    <div class="spacer" style="line-height: 11px; height: 11px;"></div>
                                    <table class="socialFollow socialFollow--padding-vertical" width="100%" cellpadding="0" cellspacing="0" border="0">
                                      <tr>
                                        <td width="100%" align="center" valign="top" style="padding-top:10px;padding-bottom:10px;height:1px;line-height:0px">
                                          <a href="https://www.facebook.com/ACCESSforCI" target="_blank">
                                            <img alt="Facebook" width="32" border="0" src="$fbIcon" style="display:inline-block;margin:0px;padding:0px">
                                          </a>
                                          <span>&nbsp;</span>&nbsp;
                                          <a href="https://twitter.com/ACCESSforCI" target="_blank" >
                                            <img alt="Twitter" width="32" border="0" src="$twIcon" style="display:inline-block;margin:0px;padding:0px">
                                          </a>
                                          <span>&nbsp;</span>&nbsp;
                                          <a href="https://www.youtube.com/c/ACCESSforCI"  target="_blank">
                                            <img alt="YouTube" width="32" border="0" src="$ytIcon" style="display:inline-block;margin:0px;padding:0px">
                                          </a>
                                        </td>
                                      </tr>
                                    </table>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                        </table>
                        <table class="layout layout--1-column" style="table-layout: fixed;" width="100%" border="0" cellpadding="0" cellspacing="0">
                          <tr>
                            <td class="column column--1 scale stack" style="width: 100%;" align="center" valign="top">
                              <div class="spacer" style="line-height: 10px; height: 10px;"></div>
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>
    </body>
    </html>
  EMAILTEXT1;
    // note: EMAILTEXT must be to the left column-wise of the last tag (php)
    return $emailText;
  }

  /**
   * "Join Affinity Groups" section
   * possible do this through a news item, but for now, we have an extra image here we need to show
   */
  private function ccRollupBottomStatic1() {
    $teamImageUrl = $this->imageUrl('team-looking-at-screen_0.jpg');
    $title = $this->titleHTML('Ensure you keep receiving updates!');
    $bodyText = "Join Affinity Groups to get updates about things you care about. If you have allocations,
                you will automatically become a member of Affinity Groups associated with your allocations.
                When you join the ACCESS Support Affinity Group you'll receive these weekly digests.";

    $buttonText = "See Affinity Groups";
    $buttonUrl = ACCESS_SUPPPORT_URL . '/affinity-groups';

    $html = <<<ROLLUPSTATIC1
    <table class="layout" style="table-layout:fixed" width="100%" border="0" cellpadding="0" cellspacing="0">
      <tbody>
        <tr>
          <td class="column scale stack" style="width:65%" align="center" valign="top">
            <table class="text" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed">
              <tbody>
                <tr>
                  <td class="text_content-cell content-padding-horizontal"
                    style="text-align:left;font-family:Roboto,sans-serif;color:#4d4d4d;font-size:14px;line-height:1.2;
                           display:block;word-wrap:break-word;padding:10px 40px"
                    align="left" valign="top">

                    $title

                    <span style="color:rgb(0,0,0)">$bodyText</span>

                  </td>
                </tr>
              </tbody>
            </table>
            <table width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed">
              <tbody>
                <tr>
                  <td class="content-padding-horizontal" align="left" style="padding:10px 40px">
                  <table style="width:inherit;border-radius:2px;border-spacing:0;background-color:#48c0b9;border:none"
                          border="0" cellpadding="0" cellspacing="0" bgcolor="#48c0b9">
                    <tbody>
                      <tr>
                        <td class="button_content-cell" style="padding:4px 6px" align="center">
                          <a href="$buttonUrl"
                            style="color:#ffffff;font-family:Roboto,sans-serif;font-size:12px;word-wrap:break-word;font-weight:bold;text-decoration:none"
                            target="_blank">
                            $buttonText
                          </a>
                        </td>
                      </tr>
                  </tbody>
                </table>
              </td>
            </tr>
          </tbody>
        </table>
      </td>
      <td class="column scale stack" style="width:35%" align="center" valign="top">
      <table class="image--mobile-scale image--mobile-center" width="100%" border="0" cellpadding="0" cellspacing="0">
        <tbody>
          <tr>
            <td class="image_container content-padding-horizontal" align="center" valign="top" style="padding:10px 40px 10px 20px">
              <img width="200" src="$teamImageUrl"
                  alt="team looking at screen" style="display:block;height:auto;max-width:100%">
              </td>
            </tr>
          </tbody>
        </table>
      </td>
    </tr>
    </tbody>
    </table>
  ROLLUPSTATIC1;
    // note: ROLLUPSTATIC1 must be to the left column-wise of the last tag (php)
    return $html;
  }

  /**
   * "Share with the ACCESS Community" section.
   */
  private function ccRollupBottomStatic2() {
    $title = $this->titleHTML('Do you have announcements or trainings to share?');
    $newsUrl = ACCESS_SUPPPORT_URL . '/announcements';
    $eventsUrl = ACCESS_SUPPPORT_URL . '/events';

    $bodyHtml = <<<BODY
      <span>Add your </span>
      <a href="$newsUrl">announcement</a>
      <span> or </span>
      <a href="$eventsUrl">event</a>
      <span> on the ACCESS Support website and we will include it in our digest.</span>
    BODY;

    $html = <<<ROLLUPSTATIC2
    <table class="layout" style="table-layout:fixed" width="100%" border="0" cellpadding="0" cellspacing="0">
      <tbody>
        <tr>
          <td class="column scale stack" style="width:100%" align="center" valign="top">
            <table class="text" width="100%" border="0" cellpadding="0" cellspacing="0" style="table-layout:fixed">
              <tbody>
                <tr>
                  <td class="text_content-cell content-padding-horizontal"
                      style="text-align:left;font-family:Roboto,sans-serif;color:#4d4d4d;font-size:14px;line-height:1.2;display:block;
                             word-wrap:break-word;padding:10px 40px"
                      align="left"
                      valign="top">

                      $title

                      $bodyHtml

                  </td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
      </tbody>
    </table>
  ROLLUPSTATIC2;
    // note: ROLLUPSTATIC2 must be to the left column-wise of the last tag (php)
    return $html;
  }

}
