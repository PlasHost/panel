<?php
/**
 * Pterodactyl - Panel
 * Copyright (c) 2015 - 2016 Dane Everitt <dane@daneeveritt.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
namespace Pterodactyl\Http\Controllers\Admin;

use Alert;
use Debugbar;
use Log;
use DB;

use Pterodactyl\Models;
use Pterodactyl\Repositories\NodeRepository;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\DisplayValidationException;

use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NodesController extends Controller
{

    /**
     * Controller Constructor
     */
    public function __construct()
    {
        //
    }

    public function getScript(Request $request, $id)
    {
        return response()->view('admin.nodes.remote.deploy', [ 'node' => Models\Node::findOrFail($id) ])->header('Content-Type', 'text/plain');
    }

    public function getIndex(Request $request)
    {
        return view('admin.nodes.index', [
            'nodes' => Models\Node::select(
                'nodes.*',
                'locations.long as a_locationName',
                DB::raw('(SELECT COUNT(*) FROM servers WHERE servers.node = nodes.id) as a_serverCount')
            )->join('locations', 'nodes.location', '=', 'locations.id')->paginate(20),
        ]);
    }

    public function getNew(Request $request)
    {
        return view('admin.nodes.new', [
            'locations' => Models\Location::all()
        ]);
    }

    public function postNew(Request $request)
    {
        try {
            $node = new NodeRepository;
            $new = $node->create($request->except([
                '_token'
            ]));
            Alert::success('Successfully created new node. <strong>Before you can add any servers you need to first assign some IP addresses and ports.</strong>')->flash();
            return redirect()->route('admin.nodes.view', [
                'id' => $new,
                'tab' => 'tab_allocation'
            ]);
        } catch (DisplayValidationException $e) {
            return redirect()->route('admin.nodes.new')->withErrors(json_decode($e->getMessage()))->withInput();
        } catch (DisplayException $e) {
            Alert::danger($e->getMessage())->flash();
        } catch (\Exception $e) {
            Log::error($e);
            Alert::danger('An unhandled exception occured while attempting to add this node. Please try again.')->flash();
        }
        return redirect()->route('admin.nodes.new')->withInput();
    }

    public function getView(Request $request, $id)
    {
        $node = Models\Node::findOrFail($id);

        return view('admin.nodes.view', [
            'node' => $node,
            'servers' => Models\Server::select('servers.*', 'users.email as a_ownerEmail', 'services.name as a_serviceName')
                ->join('users', 'users.id', '=', 'servers.owner')
                ->join('services', 'services.id', '=', 'servers.service')
                ->where('node', $id)->paginate(10, ['*'], 'servers'),
            'stats' => Models\Server::select(DB::raw('SUM(memory) as memory, SUM(disk) as disk'))->where('node', $node->id)->first(),
            'locations' => Models\Location::all(),
            'allocations' => Models\Allocation::select('allocations.*', 'servers.name as assigned_to_name')
                ->where('allocations.node', $node->id)
                ->leftJoin('servers', 'servers.id', '=', 'allocations.assigned_to')
                ->orderBy('allocations.ip', 'asc')
                ->orderBy('allocations.port', 'asc')
                ->paginate(20, ['*'], 'allocations'),
        ]);
    }

    public function postView(Request $request, $id)
    {
        try {
            $node = new NodeRepository;
            $node->update($id, $request->except([
                '_token'
            ]));
            Alert::success('Successfully update this node\'s information. If you changed any daemon settings you will need to restart it now.')->flash();
            return redirect()->route('admin.nodes.view', [
                'id' => $id,
                'tab' => 'tab_settings'
            ]);
        } catch (DisplayValidationException $e) {
            return redirect()->route('admin.nodes.view', $id)->withErrors(json_decode($e->getMessage()))->withInput();
        } catch (DisplayException $e) {
            Alert::danger($e->getMessage())->flash();
        } catch (\Exception $e) {
            Log::error($e);
            Alert::danger('An unhandled exception occured while attempting to edit this node. Please try again.')->flash();
        }
        return redirect()->route('admin.nodes.view', [
            'id' => $id,
            'tab' => 'tab_settings'
        ])->withInput();
    }

    public function deallocateSingle(Request $request, $node, $allocation)
    {
        $query = Models\Allocation::where('node', $node)->whereNull('assigned_to')->where('id', $allocation)->delete();
        if ((int) $query === 0) {
            return response()->json([
                'error' => 'Unable to find an allocation matching those details to delete.'
            ], 400);
        }
        return response('', 204);
    }

    public function deallocateBlock(Request $request, $node)
    {
        $query = Models\Allocation::where('node', $node)->whereNull('assigned_to')->where('ip', $request->input('ip'))->delete();
        if ((int) $query === 0) {
            Alert::danger('There was an error while attempting to delete allocations on that IP.')->flash();
            return redirect()->route('admin.nodes.view', [
                'id' => $node,
                'tab' => 'tab_allocations'
            ]);
        }
        Alert::success('Deleted all unallocated ports for <code>' . $request->input('ip') . '</code>.')->flash();
        return redirect()->route('admin.nodes.view', [
            'id' => $node,
            'tab' => 'tab_allocations'
        ]);
    }

    public function setAlias(Request $request, $node)
    {
        if (!$request->input('allocation')) {
            return response('Missing required parameters.', 422);
        }

        try {
            $update = Models\Allocation::findOrFail($request->input('allocation'));
            $update->ip_alias = $request->input('alias');
            $update->save();

            return response('', 204);
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public function getAllocationsJson(Request $request, $id)
    {
        $allocations = Models\Allocation::select('ip')->where('node', $id)->groupBy('ip')->get();
        return response()->json($allocations);
    }

    public function postAllocations(Request $request, $id)
    {
        $processedData = [];
        foreach($request->input('allocate_ip') as $ip) {
            if (!array_key_exists($ip, $processedData)) {
                $processedData[$ip] = [];
            }
        }

        foreach($request->input('allocate_port') as $portid => $ports) {
            if (array_key_exists($portid, $request->input('allocate_ip'))) {
                $json = json_decode($ports);
                if (json_last_error() === 0 && !empty($json)) {
                    foreach($json as &$parsed) {
                        array_push($processedData[$request->input('allocate_ip')[$portid]], $parsed->value);
                    }
                }
            }
        }

        try {
            if(empty($processedData)) {
                throw new DisplayException('It seems that no data was passed to this function.');
            }
            $node = new NodeRepository;
            $node->addAllocations($id, $processedData);
            Alert::success('Successfully added new allocations to this node.')->flash();
        } catch (DisplayException $e) {
            Alert::danger($e->getMessage())->flash();
        } catch (\Exception $e) {
            Log::error($e);
            Alert::danger('An unhandled exception occured while attempting to add allocations this node. Please try again.')->flash();
        } finally {
            return redirect()->route('admin.nodes.view', [
                'id' => $id,
                'tab' => 'tab_allocation'
            ]);
        }
    }

    public function deleteNode(Request $request, $id)
    {
        $node = Models\Node::findOrFail($id);
        $servers = Models\Server::where('node', $id)->count();
        if ($servers > 0) {
            Alert::danger('You cannot delete a node with servers currently attached to it.')->flash();
            return redirect()->route('admin.nodes.view', [
                'id' => $id,
                'tab' => 'tab_delete'
            ]);
        }

        $node->delete();
        Alert::success('Node successfully deleted.')->flash();
        return redirect()->route('admin.nodes');

    }

}
