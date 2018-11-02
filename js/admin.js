/**
 * @copyright Copyright (c) 2016 Morris Jobke <hey@morrisjobke.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

(function() {
	OCA.WorkflowScript = OCA.WorkflowScript || {};

	/**
	 * @class OCA.WorkflowScript.Operation
	 */
	OCA.WorkflowScript.Operation =
		OCA.WorkflowEngine.Operation.extend({
			defaults: {
				'class': 'OCA\\WorkflowScript\\Operation',
				'name': '',
				'checks': [],
				'operation': ''
			}
		});

	/**
	 * @class OCA.WorkflowScript.OperationsCollection
	 *
	 * collection for all configured operations
	 */
	OCA.WorkflowScript.OperationsCollection =
		OCA.WorkflowEngine.OperationsCollection.extend({
			model: OCA.WorkflowScript.Operation
		});

	/**
	 * @class OCA.WorkflowScript.OperationView
	 *
	 * this creates the view for a single operation
	 */
	OCA.WorkflowScript.OperationView =
		OCA.WorkflowEngine.OperationView.extend({
			model: OCA.WorkflowScript.Operation,
			render: function() {
				var $el = OCA.WorkflowEngine.OperationView.prototype.render.apply(this);
				$el.find('input.operation-operation')
					.css('width', '400px')
					.attr("placeholder", t('workflow_script', 'command to execute'))
			}
		});

	/**
	 * @class OCA.WorkflowScript.OperationsView
	 *
	 * this creates the view for configured operations
	 */
	OCA.WorkflowScript.OperationsView =
		OCA.WorkflowEngine.OperationsView.extend({
			initialize: function() {
				OCA.WorkflowEngine.OperationsView.prototype.initialize.apply(this, [
					'OCA\\WorkflowScript\\Operation'
				]);
			},
			renderOperation: function(operation) {
				var subView = new OCA.WorkflowScript.OperationView({
					model: operation
				});

				OCA.WorkflowEngine.OperationsView.prototype.renderOperation.apply(this, [
					subView
				]);
			}
		});
})();


$(document).ready(function() {
	OC.SystemTags.collection.fetch({
		success: function() {
			new OCA.WorkflowScript.OperationsView({
				el: '#workflow_script .rules',
				collection: new OCA.WorkflowScript.OperationsCollection()
			});
		}
	});
});
