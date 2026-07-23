<?php

/**
 * avuz_search_notice — warn before an expensive whole-message search.
 *
 * Zoho's IMAP has no SORT, THREAD or MULTISEARCH, and a body/TEXT search costs
 * real server-side compute on their side: 69s measured on this deployment for a
 * single all-folder body search. No amount of client or round-trip work removes
 * that, so the honest thing is to set the expectation before the user waits.
 *
 * Client-side only: it hooks the existing search UI rather than the search
 * itself, so it cannot slow down or break a search.
 */
class avuz_search_notice extends rcube_plugin
{
    public $task = 'mail';

    function init()
    {
        $this->include_script('search_notice.js');
        $this->add_texts('localization/', true);
    }
}
