/**
 * Javascript to fetch and show outages associated with affinity groups.
 *
 * CiDeR ids are pass to this from the
 * access_outages.module::access_outages_preprocess_views_view()
 */

/**
 * Found it was necessary to include this wrapper or subsequent functions
 * would get null pointers when trying to get dom elements
 */
var waitForJQuery = setInterval(function () {
  if (typeof jQuery != 'undefined') {

    // since current & planned outages may be sparse, the following
    // boolean can be used for debugging / testing -- it forces the
    // retrieval of all outages
    const bDebugWithAllOutages = false

    const ciderIds = drupalSettings.ciderIds
    // only show outages if there are any ciderIds
    if (bDebugWithAllOutages || (ciderIds && ciderIds.length > 0)) {
      showAgOutages(ciderIds, bDebugWithAllOutages)
    }

    clearInterval(waitForJQuery);
  }
}, 10);

/**
 * Make API calls for current & future outages, then filter them
 * for resources on the list parameter
 *
 * @param {*} ciderIds -- array of resources -- look for outages for these ids
 * @param {*} bDebugWithAllOutages -- debug with *all* outages
 */
const showAgOutages = async function showAgOutages(ciderIds, bDebugWithAllOutages) {

  // make the api calls and show results
  showCurrentOutages(ciderIds, bDebugWithAllOutages)
  showPlannedOutages(ciderIds, bDebugWithAllOutages)
}

/**
 * Current outages are shown in boxes in a div at top of page.
 * Only add the div if there are any associated outages.
 * Make the api call, filter results, and display any outages
 * (or display nothing if no results).
 *
 * @param {*} ciderIds
 * @param {*} bDebugWithAllOutages -- debug with *all* outages
 */
const showCurrentOutages = async function showCurrentOutages(ciderIds, bDebugWithAllOutages) {
  const outagesCurrentId = document.getElementById(`outages-current`)

  // for testing, get all outages
  const endpointUrl = bDebugWithAllOutages
    ? 'https://operations-api.access-ci.org/wh2/news/v1/affiliation/access-ci.org/all_outages'
    : 'https://operations-api.access-ci.org/wh2/news/v1/affiliation/access-ci.org/current_outages'

  const response = await fetch(endpointUrl)
  let outages = await response.json()
  let filtered = bDebugWithAllOutages ? outages.results : filterOutages(outages.results, ciderIds)

  if (bDebugWithAllOutages & filtered.length > 4) filtered.length = 4 // keep debugging simple

  if (filtered.length > 0) {

    // create a div for current outages
    let outagesCurrentDiv = document.createElement('div')
    outagesCurrentDiv.innerHTML = `
      <div id="outages-current" class="outage-current" >
        <p id="outages-current-p">
        </p>
      </div>
    `

    // add the div to the page
    let container = document.querySelector('main .layout-content')
    container.insertBefore(outagesCurrentDiv, container.firstChild)
    const outagesCurrent = document.getElementById('outages-current-p')

    // for all the filtered outages, add a link in a box to the outage to the
    // current outages div
    let outageHtml = ''
    for (const outage of filtered) {
      outageHtml += getOutageHtml(outage)
    }
    outagesCurrent.innerHTML = outageHtml
  }
}

/**
 * Helper function to generate the html link to an outage
 *
 * @param {*} outage
 * @returns a chunk of html with the link to the outage
 */
function getOutageHtml(outage) {
  return `
    <a style="text-decoration: none" href="/outages?outageID=` + outage['URN'] + `">
      <span class="outage-span">
        <span style="color: #f07537; font-size: 170%"> &bull; </span>
        Status Alert
      </span>
    </a>
    &nbsp;
  `
}

/**
 * Planned outages are shown in an html datatable.
 * Make the API call and filter results against the list in ciderIds and
 * fill the the datatable
 *
 * @param {*} ciderIds
 * @param {*} bDebugWithAllOutages -- debug with *all* outages
 */
const showPlannedOutages = async function showPlannedOutages(ciderIds, bDebugWithAllOutages) {

  // for testing, get all outages
  const endpointUrl = bDebugWithAllOutages
    ? 'https://operations-api.access-ci.org/wh2/news/v1/affiliation/access-ci.org/all_outages'
    : 'https://operations-api.access-ci.org/wh2/news/v1/affiliation/access-ci.org/future_outages'

  const response = await fetch(endpointUrl)
  let outages = await response.json()
  let filtered = bDebugWithAllOutages ? outages.results : filterOutages(outages.results, ciderIds)

  if (bDebugWithAllOutages & filtered.length > 4) filtered.length = 4

  if (filtered.length > 0) {

    // create the html table
    addOutageTableHtmlToDom()

    const options = {
      timeZoneName: 'short'
    }

    const outagesTable = document.getElementById('ag-outages-planned')

    jQuery(outagesTable).DataTable({
      data: filtered,
      columns: [
        {
          data: 'Subject',
          render: function (data, type, row, meta) {
            return type === 'display' ? `<a href="/outages?outageID=${row['URN']}">${data}</a>` : data;
          }
        },
        {
          data: 'OutageStart',
          render: function (data, type, row, meta) {
            return type === 'display'
              ? (data ? new Date(data).toLocaleString(navigator.language, options) : '')
              : data;
          }
        },
        {
          data: 'OutageEnd',
          render: function (data, type, row, meta) {
            return type === 'display'
              ? (data ? new Date(data).toLocaleString(navigator.language, options) : '')
              : data;
          }
        }
      ],
      order: [[1, 'desc']], // sort by OutageStart
      bPaginate: false,
      bAutoWidth: false,
      searching: false
    })

    outagesTable.style.display = 'block'
  }
}

/**
 * Add the planned outages table to the DOM
 */
function addOutageTableHtmlToDom() {

  let outagesTableDiv = document.createElement('div')
  outagesTableDiv.innerHTML = `
    <div class="outage-list section container">
      <div class="row">
        <div class="mb-3">
          <h3 class="pb-2">Planned Downtime for Associated Infrastructure</h3>
          <div class="table-responsive">
            <table id="ag-outages-planned" class="display text-start table" style="display:none;">
              <thead>
                <tr>
                  <th>Event</th>
                  <th>Start</th>
                  <th>End</th>
                </tr>
              </thead>
              <tbody>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  `
  // This is shown on the Affinity Group pages.
  container = document.querySelector('.page-node-type-affinity-group main .layout-content .layout__region--first > div')
  const sibling = container.querySelector('.block-field-blocknodeaffinity-groupfield-cider-resources')
  container.insertBefore(outagesTableDiv, sibling);
}

/**
 * Given a list of outages, return an array containing only those with an id
 * in the ciderIds list
 *
 * @param {*} outages
 * @param {*} ciderIds
 * @returns
 */
function filterOutages(outages, ciderIds) {
  let filtered = []
  Object.keys(outages).forEach(function (key) {
    for (const resource of outages[key]['AffectedResources']) {
      if (ciderIds.indexOf(resource.ResourceID) > -1) {
        if (filtered.indexOf(outages[key]) === -1) {
          filtered.push(outages[key]);
        }
      }
    }
  });
  return filtered;
}
