<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\View\View;

/** Contact details from settings (support_phone / support_email). No form: nothing is sent from here. */
class ContactController extends SiteController
{
    public function __invoke(Request $request): View
    {
        return $this->render($request, 'site.contact');
    }
}
