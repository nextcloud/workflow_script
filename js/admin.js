/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

(function() {

	var Component = {
		name: 'WorkflowScript',
		render: function (createElement) {
			var self = this
			return createElement('div', {
				style: {
					width: '100%'
				},
			}, [
				createElement('input', {
					attrs: {
						type: 'text'
					},
					domProps: {
						value: self.value,
						required: 'true'
					},
					style: {
						width: '100%'
					},
					on: {
						input: function (event) {
							self.$emit('input', event.target.value)
						}
					}
				}),
				createElement('a', {
					attrs: {
						href: self.link,
						target: '_blank'
					},
					style: {
						color: 'var(--color-text-maxcontrast)'
					}
				}, self.description)
			])
		},
		props: {
			value: ''
		},
		data: function () {
			return {
				description: t('workflow_script', 'Available placeholder variables are listed in the documentation') + '↗',
				link: 'https://github.com/nextcloud/workflow_script#placeholders'
			}
		}
	};

	OCA.WorkflowEngine.registerOperator({
		id: 'OCA\\WorkflowScript\\Operation',
		operation: '',
		options: Component
	});

})();
