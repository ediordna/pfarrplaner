<?php
/**
 * Pfarrplaner
 *
 * @package Pfarrplaner
 * @author Christoph Fischer <chris@toph.de>
 * @copyright (c) 2020 Christoph Fischer, https://christoph-fischer.org
 * @license https://www.gnu.org/licenses/gpl-3.0.txt GPL 3.0 or later
 * @link https://github.com/potofcoffee/pfarrplaner
 * @version git: $Id$
 *
 * Sponsored by: Evangelischer Kirchenbezirk Balingen, https://www.kirchenbezirk-balingen.de
 *
 * Pfarrplaner is based on the Laravel framework (https://laravel.com).
 * This file may contain code created by Laravel's scaffolding functions.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Reports;

use App\City;
use App\Day;
use App\Service;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;


/**
 * Class ChildrensChurchReport
 * @package App\Reports
 */
class ChildrensChurchReport extends AbstractPDFDocumentReport
{

    /**
     * @var string
     */
    public $title = 'Programm für die Kinderkirche';
    /**
     * @var string
     */
    public $group = 'Listen';
    /**
     * @var string
     */
    public $description = 'Übersicht aller Termine der Kinderkirche mit Themen und Mitarbeitern';

    /**
     * @return Application|Factory|View
     */
    public function setup()
    {
        $maxDate = Day::orderBy('date', 'DESC')->limit(1)->get()->first();
        $cities = Auth::user()->cities;
        return $this->renderSetupView(['maxDate' => $maxDate, 'cities' => $cities]);
    }

    /**
     * @param Request $request
     * @return mixed|string
     */
    public function render(Request $request)
    {
        $request->validate(
            [
                'city' => 'required',
                'start' => 'required|date|date_format:d.m.Y',
                'end' => 'required|date|date_format:d.m.Y',
            ]
        );

        $days = Day::where('date', '>=', Carbon::createFromFormat('d.m.Y', $request->get('start')))
            ->where('date', '<=', Carbon::createFromFormat('d.m.Y', $request->get('end')))
            ->orderBy('date', 'ASC')
            ->get();

        $city = City::find($request->get('city'));

        $serviceList = [];
        foreach ($days as $day) {
            $serviceList[$day->date->format('Y-m-d')] = Service::with(['location', 'day'])
                ->where('day_id', $day->id)
                ->where('cc', 1)
                ->where('city_id', $city->id)
                ->orderBy('time', 'ASC')
                ->get();
        }

        $dates = [];
        foreach ($serviceList as $day => $services) {
            foreach ($services as $service) {
                $dates[] = $service->day->date;
            }
        }

        $minDate = min($dates);
        $maxDate = max($dates);

        return $this->sendToBrowser(
            date('Ymd') . ' Kinderkirche ' . $city->name . '.pdf',
            [
                'start' => $minDate,
                'end' => $maxDate,
                'city' => $city,
                'services' => $serviceList,
                'count' => count($dates),
            ],
            ['format' => 'A4']
        );
    }

}
