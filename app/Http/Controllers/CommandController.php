<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\User;
use App\Models\Qwater;
use App\Models\Command;
use App\Models\Country;
use App\Models\CommandLine;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommandController extends Controller
{
    public function index($state = null)
    {
        return $state ? response()->json(Command::where('state', $state)::with(['commandlines' => function ($query) {
            $query->with(['products']);
        }])->get()->map(function ($command) {
            $command->details_url = route('commandes.details', $command->id);
            return $command;
        }), 200) 
        : response()->json(Command::with(['commandlines' => function ($query) {
            $query->with(['product']);
        }, 'user', 'qwater' => function ($query) {
            $query->with(['city' => function ($query) {
                $query->with(['country']);
            }]);
        }, ])->get()->map(function ($command) {
            $command->details_url = route('commandes.details', $command->id);
            return $command;
        }), 200);
    }

    public function price()
    {
        $price = 0;
        foreach (Command::where('state', 'disabled')->with(['commandlines' => function ($query) {
            $query->with(['product']);
        }])->get() as $command) {
            foreach ($command->commandlines as $commandlines) {
                $price += $commandlines->product->price * $commandlines->quantity;
            }
        }
        return response()->json([[
            'price' => $price,
            'command_count' => Command::where('state', 'disabled')->count(),
        ]], 200);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $messages = [];
        $qwater = $city = $country = $commande = null;

        // Ce bloc de code a pour objectif de creer le quartier et passer la  commande
        if($request->qwater_id == null || empty($request->qwater_id)) {
            if ($request->city_id == null || empty($request->city_id)) {
                if ($request->country_id == null || empty($request->country_id)) {
                    if (!self::validate($request->country_name, 'string', null, 3)) $messages[] = 'Nom de pays incorrect';
                    if (!self::validate($request->city_name, 'string', null, 3)) $messages[] = 'Nom de ville incorrect';
                    if (!self::validate($request->qwater_name, 'string', null, 3)) $messages[] = 'Nom de quartier incorrect';
                    
                    if (count($messages)  == 0) {
                        $country = Country::firstOrCreate(['name' => $request->country_name]);
                        if($country->wasRecentlyCreated){
                            $city = City::firstOrCreate([
                                'name' => $request->city_name, 
                                'countrie_id' => $country->id 
                            ]);
                            if ($city->wasRecentlyCreated) {
                                $qwater = Qwater::firstOrCreate([
                                    'name' => $request->qwater_name, 
                                    'citie_id' => $city->id 
                                ]);
                                if(!$qwater->wasRecentlyCreated)return response()->json(['messages' => 'Ce quartier existe déjà'], 500);
                            } else {
                                return response()->json(['messages' => 'Cette ville existe déjà'], 500);
                            }
                        }
                        else {
                            return response()->json(['messages' => 'Ce pays existe déjà'], 500);
                        }
                    } else {
                        return response()->json(['messages' => $messages], 500);
                    } 
                }
                else {
                    if (!self::validate($request->city_name, 'string', null, 3)) $messages[] = 'Nom de ville incorrect';
                    if (!self::validate($request->qwater_name, 'string', null, 3)) $messages[] = 'Nom de quartier incorrect';
                    
                    if (count($messages)  == 0) {
                        $city = City::firstOrCreate([
                            'name' => $request->city_name, 
                            'countrie_id' => $request->country_id
                        ]);
                        if ($city->wasRecentlyCreated) {
                            $qwater = Qwater::firstOrCreate([
                                'name' => $request->qwater_name, 
                                'citie_id' => $city->id 
                            ]);
                            if(!$qwater->wasRecentlyCreated) return response()->json(['messages' => 'Ce quartier existe déjà'], 500);
                        } else {
                            return response()->json(['messages' => 'Cette ville existe déjà'], 500);
                        }
                    } else {
                        return response()->json(['messages' => $messages], 500);
                    } 
                    
                }
            }
            else {
                $qwater = Qwater::firstOrCreate([
                    'name' => $request->qwater_name, 
                    'citie_id' => $request->city_id
                ]);
                if(!$qwater->wasRecentlyCreated) return response()->json(['messages' => 'Ce quartier existe déjà'], 500);
            }

            $commande = Command::create([
                'qwater_id' => $qwater->_id, 
                'payment_id' => $request->payment_id ?? null,
                'user_id' => Auth::user()->id, 
                'state' => 'init',
            ]);
        }
        // ayant l'id du quartier, on passe juste la commande
        else {
            $commande = Command::create([
                'qwater_id' => $request->qwater_id, 
                'payment_id' => $request->payment_id ?? null,
                'user_id' => Auth::user()->id, 
                'state' => 'init',
            ]);
        }

        // Une fois la commande crée on doit chargé les produits commandés dans la commande
        // Pour cela on se sert du panier et on recupera toutes les lignes du panier qu'on chargera dans la commande
        foreach (Auth::user()->cart->cartlines->where('state', 'init') as $cartline) {
            CommandLine::create([
                'product_id' => $cartline->product_id, 
                'command_id' => $commande->id, 
                'quantity' => $cartline->quantity, 
                'attributes_values' =>  $cartline->attributesValues, 
            ]);

            // Modification des etats des lignes du panier à enabled car la commande n'a pas encore ete validé
            $cartline->update(['state' => 'enabled']);

            // On envoie une notif a chaque vendeur
            Notification::create([
                'user_id' => $cartline->product->shop->user_id, 
                'text' => 'Nouvelle commande initié', 
                'type' => 'admin',
                'state' => 'init',
            ]);
        }

        // On envoie une notification aux admin
        foreach (User::where('type', 'admin')->get() as $user) {
            Notification::create([
                'user_id' => $user->id, 
                'text' => 'Nouvelle commande initié', 
                'type' => 'admin',
                'state' => 'init',
            ]);
        }

        return response()->json(['message' => 'commande inité avec succès'], 200);      
    }

    public function show(Command $command)
    {
        //
    }

    public function edit(Command $command)
    {
        //
    }

    public function update(Request $request, Command $command)
    {
        //
    }

    public function destroy(Request $request)
    {
        Command::find($request->id)->delete();
        return response()->json(['message' => 'Commande supprimé avec succès !'], 200);
    }

    public function usersHistory ()   
    {
        return response()->json(Auth::user()->commandes->where('state', 'disabled'), 200);
    }

}
