<?php
/**
 * Campaign Connector — Module Registry
 *
 * Lists all modules that implement the CampaignAware interface.
 * The automation_runner and Opportunities Dashboard scan this list to
 * discover which events exist and what payload schema they carry.
 *
 * To register a new module:
 *  1. Create a class implementing CampaignAware in your module directory
 *  2. Add it to this array (key = source_module string used in CampaignEventEmitter::fire)
 */
return [
    'invoices'  => __DIR__ . '/Emitters/InvoicesCampaignEmitter.php',
    'quotes'    => __DIR__ . '/Emitters/QuotesCampaignEmitter.php',
    'jobflow'   => __DIR__ . '/Emitters/JobFlowCampaignEmitter.php',
    'crew_app'  => __DIR__ . '/Emitters/CrewAppCampaignEmitter.php',
];
