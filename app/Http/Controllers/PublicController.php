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

namespace App\Http\Controllers;

use App\City;
use App\Day;
use App\Service;
use App\User;
use Carbon\Carbon;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use niklasravnsborg\LaravelPdf\Facades\Pdf;

/**
 * Class PublicController
 * @package App\Http\Controllers
 */
class PublicController extends Controller
{
    /**
     * @var array
     */
    protected $vacationData = [];

    /**
     * @return Application|Factory|View
     */
    public function absences()
    {
        $start = Carbon::now()
            ->setTime(0, 0, 0);
        $end = Carbon::createFromDate($start->year, $start->month, 1)
            ->setTime(0, 0, 0)
            ->addMonth(2)
            ->subSecond(1);

        $vacations = $this->getVacationers($start, $end);
        $cities = City::orderBy('name', 'ASC')->get();

        return view(
            'public.absences',
            [
                'vacations' => $vacations,
                'start' => $start,
                'end' => $end,
                'cities' => $cities,
            ]
        );
    }

    /**
     * @param $start
     * @param $end
     * @return array
     */
    protected function getVacationers($start, $end)
    {
        $vacationers = [];
        if (env('VACATION_URL')) {
            if (!count($this->vacationData)) {
                $this->vacationData = json_decode(file_get_contents(env('VACATION_URL')), true);
            }
            foreach ($this->vacationData as $key => $datum) {
                $vacStart = Carbon::createFromTimeString($datum['start']);
                $vacEnd = Carbon::createFromTimeString($datum['end']);
                if ((($vacStart < $start) && ($vacEnd >= $start)) || (($vacStart >= $start) && ($vacStart <= $end))) {
                    if (preg_match('/(?:U:|FB:) (\w*)/', $datum['title'], $tmp)) {
                        preg_match('/V: ((?:\w|\/)*)/', $datum['title'], $tmp2);
                        $sub = [];
                        foreach (explode('/', $tmp2[1]) as $name) {
                            $sub[] = $this->findUserByLastName(trim($name));
                        }

                        $vacationers[] = [
                            'away' => $this->findUserByLastName($tmp[1]),
                            'substitute' => $sub,
                            'start' => $vacStart,
                            'end' => $vacEnd,
                        ];
                    }
                }
            }
        }
        return $vacationers;
    }

    /**
     * @param $lastName
     * @return Builder|Model|object|null
     */
    protected function findUserByLastName($lastName)
    {
        return User::with('cities')->where('name', 'like', '%' . $lastName)->first();
    }

    /**
     * @param Request $request
     * @param $city
     * @return Factory|RedirectResponse|View
     */
    public function childrensChurch(Request $request, $city)
    {
        $city = City::where('name', 'like', '%' . $city . '%')->first();
        if (!$city) {
            return redirect()->route('home');
        }

        $minDate = Carbon::now();
        $maxDate = Day::orderBy('date', 'DESC')->limit(1)->get()->first()->date;

        $days = Day::where('date', '>=', $minDate)
            ->where('date', '<=', $maxDate)
            ->orderBy('date', 'ASC')
            ->get();

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

        if (count($dates)) {
            $minDate = min($dates);
            $maxDate = max($dates);
        }

        if ($request->is('*/pdf')) {
            $pdf = Pdf::loadView(
                'reports.childrenschurch.render',
                [
                    'start' => $minDate,
                    'end' => $maxDate,
                    'city' => $city,
                    'services' => $serviceList,
                    'count' => count($dates),
                ]
            );
            $filename = $minDate->format('Ymd') . '-' . $maxDate->format(
                    'Ymd'
                ) . ' Kinderkirche ' . $city->name . '.pdf';
            return $pdf->stream($filename);
        }

        return view(
            'public.cc',
            [
                'start' => $minDate,
                'end' => $maxDate,
                'city' => $city,
                'services' => $serviceList,
                'count' => count($dates),
            ]
        );
    }
}
