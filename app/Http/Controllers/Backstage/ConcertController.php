<?php

namespace App\Http\Controllers\Backstage;

use App\NullFile;
use Carbon\Carbon;
use App\Events\ConcertAdded;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class ConcertController extends Controller
{
    public function index()
    {
        return view('backstage.concerts.index', [
            'publishedConcerts' => Auth::user()->concerts->filter->isPublished(),
            'unpublishedConcerts' => Auth::user()->concerts->reject->isPublished(),
        ]);
    }

    public function create()
    {
        return view('backstage.concerts.create');
    }

    public function store()
    {
        request()->validate([
            'title' => 'required',
            'subtitle' => 'nullable',
            'additional_info' => 'nullable',
            'date' => 'required|date',
            'time' => 'required|date_format:g:ia',
            'venue' => 'required',
            'venue_address' => 'required',
            'city' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'ticket_price' => 'required|numeric|min:5',
            'ticket_quantity' => 'required|numeric|min:1',
            'poster_image' => 'nullable|image|dimensions:min_width=400|dimensions:ratio=8.5/11',
        ]);

        $concert = Auth::user()->concerts()->create([
            'title' => request('title'),
            'subtitle' => request('subtitle'),
            'additional_info' => request('additional_info'),
            'date' => Carbon::parse(vsprintf('%s %s', [
                request('date'),
                request('time'),
            ])),
            'venue' => request('venue'),
            'venue_address' => request('venue_address'),
            'city' => request('city'),
            'state' => request('state'),
            'zip' => request('zip'),
            'ticket_price' => request('ticket_price') * 100,
            'ticket_quantity' => (int)request('ticket_quantity'),
            'poster_image_path' => request('poster_image', new NullFile)->store('posters', 'public'),
        ]);

        ConcertAdded::dispatch($concert);

        return redirect()->route('backstage.concerts.index');
    }

    public function edit($id)
    {
        $concert = Auth::user()->concerts()->findOrFail($id);

        abort_if($concert->isPublished(), 403);

        return view('backstage.concerts.edit', [
            'concert' => $concert,
        ]);
    }

    public function update($id)
    {
        $concert = Auth::user()->concerts()->findOrFail($id);

        abort_if($concert->isPublished(), 403);

        request()->validate([
            'title' => 'required',
            'date' => 'required|date',
            'time' => 'required|date_format:g:ia',
            'venue' => 'required',
            'venue_address' => 'required',
            'city' => 'required',
            'state' => 'required',
            'zip' => 'required',
            'ticket_price' => 'required|numeric|min:5',
            'ticket_quantity' => 'required|integer|min:1',
        ]);

        $concert->update([
            'title' => request('title'),
            'subtitle' => request('subtitle'),
            'additional_info' => request('additional_info'),
            'date' => Carbon::parse(vsprintf('%s %s', [
                request('date'),
                request('time'),
            ])),
            'venue' => request('venue'),
            'venue_address' => request('venue_address'),
            'city' => request('city'),
            'state' => request('state'),
            'zip' => request('zip'),
            'ticket_price' => request('ticket_price') * 100,
            'ticket_quantity' => (int)request('ticket_quantity'),
        ]);

        return redirect()->route('backstage.concerts.index');
    }
}
